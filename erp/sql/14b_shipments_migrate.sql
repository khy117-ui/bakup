-- ============================================================================
--  매출전표 — [B] 데이터 이관
--  실행 순서 : 10 → 11 → 12 → 13a → 14a → (CSV 적재) → **13b → 14b**
--
--  ★ 이 파일을 돌리기 전에 staging 에 원본 CSV 가 들어가 있어야 합니다.
--    15_staging_import.sql 을 먼저 보세요. staging 이 비어 있으면
--    아무 오류 없이 0건이 이관되고 끝납니다.
--
--  ★ 관리자 계정으로 실행하세요. 앱 계정에는 일부 테이블 권한이 없습니다.
--
--  다시 돌릴 때 : 이미 들어간 행이 있으면 UNIQUE 로 막힙니다.
--    처음부터 다시 하려면 아래를 먼저 실행하세요 (staging 은 남습니다).
--      DELETE FROM shipment_charges;
--      DELETE FROM shipment_parties;
--      DELETE FROM shipment_items;
--      DELETE FROM purchases;
--      DELETE FROM documents;
--      DELETE FROM shipments_legacy_extra;
--      DELETE FROM shipments;
--      DELETE FROM shipments_history;
--      DELETE FROM migration_errors WHERE source_table = 'IS_SALES';
-- ============================================================================

SET NAMES utf8mb4;

-- ============================================================================
--  5. 대체 마스터 — 연결이 안 되는 전표도 버리지 않기 위한 자리
--
--  거래처를 못 찾은 전표를 버리면 매출 총합이 어긋납니다. 대체 거래처에 붙이고
--  migration_flag 로 표시한 뒤, 사람이 화면에서 올바른 거래처로 옮깁니다.
-- ============================================================================

INSERT INTO companies (company_code, name_ko, trade_status, memo)
VALUES ('UNMATCHED', '(거래처 미확인)', 'SUSPENDED',
        '이관 시 상호명으로 거래처를 찾지 못한 전표를 임시로 붙이는 자리')
ON DUPLICATE KEY UPDATE name_ko = VALUES(name_ko);

INSERT INTO carriers (code, name, default_tax_type, sort_order, is_active)
VALUES ('UNKNOWN', '(운송사 미확인)', 'ZERO', 999, 0)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- 운송사(파트너) — 옛 IS_SALES.TRANSIT_A 에 적힌 이름을 그대로 운송사로 만듭니다.
--
--   ★ 운송사는 TRANSIT_A 에 있었습니다 (2026-09-18 확인). 90가지 값 —
--     일양익스프레스(매출 35.7%) · TNT · 삼중포장 EMS · PANTOS DHL · 유니월드 · ADP …
--     'PANTOS DHL' 과 'DHL' 은 원가가 다른 별개 경로라 합치지 않습니다.
--
--   이미 있는 DHL / FEDEX / UPS / EMS 와 코드나 이름이 같으면 새로 만들지 않고 그걸 씁니다.
--   2025년 이후에 쓴 곳만 사용(1), 그 이전에만 쓴 곳은 중지(0) — 입력 목록에서 숨고
--   지난 전표에는 그대로 남습니다. TNT 도 이렇게 **과거 기록으로** 남깁니다
--   (예전에는 TNT 를 FEDEX 로 바꿔 넣으려 했지만, 그러면 지난 운송사 통계가 틀립니다).
--   코드는 이름에서 만든 자동코드입니다. 운송사 관리 화면에서 바꿀 수 있습니다.
INSERT INTO carriers (code, name, default_tax_type, sort_order, is_active)
SELECT CONCAT('T', UPPER(LEFT(MD5(t.nm), 7))), t.nm, 'ZERO', 500,
       CASE WHEN t.last_date >= '2025-01-01' THEN 1 ELSE 0 END
FROM (
    SELECT TRIM(TRANSIT_A) AS nm, MAX(TRIM(SALEDATE)) AS last_date
    FROM shipments_staging
    WHERE COALESCE(TRIM(TRANSIT_A), '') <> ''
    GROUP BY TRIM(TRANSIT_A)
) t
WHERE NOT EXISTS (SELECT 1 FROM carriers c
                   WHERE UPPER(c.code) = UPPER(t.nm) OR c.name = t.nm)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- 상호가 달라도 같은 회사로 확인된 것 — 사람이 확인한 것만 넣습니다.
-- 더 확인되면 여기에 줄을 더하고 14b 를 다시 돌리면 됩니다.
CREATE TABLE IF NOT EXISTS migration_company_aliases (
  legacy_name  VARCHAR(150) NOT NULL COMMENT '매출전표(IS_SALES.COMPANY)에 적힌 상호',
  company_code VARCHAR(30)  NOT NULL COMMENT '붙일 거래처 코드',
  confirmed_by VARCHAR(50)  NULL,
  confirmed_on DATE         NULL,
  memo         VARCHAR(255) NULL,
  PRIMARY KEY (legacy_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='이관용 거래처 별칭 — 사람이 확인한 것만';

INSERT INTO migration_company_aliases (legacy_name, company_code, confirmed_by, confirmed_on, memo) VALUES
  ('(주) 웰더스 스마트 케어', '01-W005', '사용자', '2026-09-18', '주식회사 웰더스스마트케어 · 전표 91건'),
  ('마리',                    '01-M001', '사용자', '2026-09-18', '마리(MARY) · 전표 54건')
ON DUPLICATE KEY UPDATE company_code = VALUES(company_code);


-- ============================================================================
--  6. 전표 이관 — 한 건도 빠지지 않게
--
--  거래처 연결 : IS_SALES.COMPANY(상호) → companies.name_ko 정확일치
--                → 실패하면 (거래처 미확인) 에 붙이고 표시
--  운송사 연결 : DANGA_NAME 에서 키워드 추출 (TNT 는 FEDEX 로)
--                → 실패하면 (운송사 미확인) 에 붙이고 표시
--  전표일     : SALEDATE → 실패 시 DATESHIP → 실패 시 REGDATE → 최후 1900-01-01
--               (NOT NULL 이라 값이 필요합니다. 1900-01-01 은 '확인 필요' 표시입니다)
-- ============================================================================

INSERT INTO shipments (
  business_entity_id, company_id, awb_no, awb_source, mawb_no,
  voucher_date, ship_date, trade_type, carrier_id,
  dest_city, actual_weight, charge_weight, package_count,
  snap_base_price, snap_discount_rate, snap_fuel_rate, snap_taken_at,
  status, sales_team, sales_rep, remark,
  created_at, updated_at, legacy_idx, migration_flag
)
SELECT
  -- 12_seed.sql 을 건너뛰면 여기가 NULL 이 되고, business_entity_id 는 NOT NULL 이라
  -- **에러로 멈춥니다.** 예전 CROSS JOIN 방식은 조용히 0건만 넣고 끝났습니다
  (SELECT id FROM business_entities WHERE code = 'GPA'),
  -- 동명 거래처가 둘 이상이면 예전 LEFT JOIN 은 행을 불려서 AWB 중복 오류를 냈습니다.
  -- 스테이징 1행 → 1행이 보장되는 파생표로 바꿨습니다
  COALESCE(cm.company_id, (SELECT id FROM companies WHERE company_code = 'UNMATCHED')),
  -- AWB 번호가 없거나 **중복이면** 임시번호를 부여합니다. 전표를 버리지 않습니다.
  -- (business_entity_id, awb_no) 가 UNIQUE 이므로 중복을 그대로 넣으면
  -- INSERT 전체가 실패하고 전표가 한 건도 들어가지 않습니다
  CASE
    WHEN COALESCE(TRIM(s.BLNUM), '') = ''
         THEN CONCAT('NOAWB-', s.staging_id)
    WHEN EXISTS (SELECT 1 FROM shipments_staging p
                  WHERE TRIM(COALESCE(p.BLNUM, '')) = TRIM(s.BLNUM)
                    AND p.staging_id < s.staging_id)
         THEN CONCAT(TRIM(s.BLNUM), '-D', s.staging_id)
    ELSE TRIM(s.BLNUM)
  END,
  'HOUSE',
  NULLIF(TRIM(s.TSNUM), ''),
  COALESCE(d.saledate_d, d.dateship_d, d.regdate_d, '1900-01-01'),
  d.dateship_d,
  -- 값이 알 수 없는 것이면 EXPORT 로 두되 아래 migration_flag 에 표시합니다.
  -- 조용히 단정하면 수출/수입 통계가 틀어집니다
  -- ★ 실제 값은 '1' / '2' 입니다 (2026-09-18 IS_SALES 49,360건 확인).
  --   1 = 수출 : 도착지가 CHINA 14,790 · USA 4,576 · HONG KONG · JAPAN … 전부 해외
  --   2 = 수입 : 도착지가 SEOUL 4,001 / 4,193 (95%), 출발지는 CHINA · GERMANY · TAIWAN
  --   예전 코드는 '1' / '2' 를 몰라서 전부 EXPORT 로 넣었습니다 — 수입 4,193건이 수출로 잡혔습니다
  CASE WHEN UPPER(TRIM(COALESCE(s.INOUT, ''))) IN ('2', 'I', 'IN', 'IMPORT', '수입') THEN 'IMPORT'
       WHEN UPPER(TRIM(COALESCE(s.INOUT, ''))) IN ('1', 'O', 'OUT', 'E', 'EXPORT', '수출') THEN 'EXPORT'
       ELSE 'EXPORT' END,
  COALESCE(ca.id, (SELECT id FROM carriers WHERE code = 'UNKNOWN')),
  NULLIF(TRIM(s.ARRIVAL_N), ''),
  n.weight_d, n.weight_d,
  CASE WHEN TRIM(COALESCE(s.PCS, '')) REGEXP '^[0-9]+$'
       THEN CAST(s.PCS AS SIGNED) ELSE NULL END,
  n.price_d, n.dcyul_d, n.fuel_d,
  CASE WHEN n.price_d IS NOT NULL THEN COALESCE(d.regdate_d, d.saledate_d) ELSE NULL END,
  CASE WHEN UPPER(TRIM(COALESCE(s.DEPOSIT, 'N'))) = 'Y' THEN 'PAID'
       WHEN UPPER(TRIM(COALESCE(s.BILL,    'N'))) = 'Y' THEN 'BILLED'
       ELSE 'CONFIRMED' END,
  NULLIF(TRIM(s.TEAM), ''),
  COALESCE(NULLIF(TRIM(s.COMM), ''), NULLIF(TRIM(s.SPOT), '')),
  NULLIF(CONCAT_WS(' / ', NULLIF(TRIM(s.CONTENTS), ''), NULLIF(TRIM(s.remark), '')), ''),
  COALESCE(d.regdate_d, NOW()),
  COALESCE(STR_TO_DATE(LEFT(TRIM(s.UPTDATE), 19), '%Y-%m-%d %H:%i:%s'), d.regdate_d, NOW()),
  CAST(NULLIF(TRIM(s.IDX), '') AS SIGNED),
  NULLIF(CONCAT_WS(' / ',
    CASE WHEN cm.company_id IS NULL THEN '거래처미확인' END,
    CASE WHEN ca.id IS NULL         THEN '운송사미확인' END,
    -- TRANSIT_A 가 비어서 AWB 번호 형태로 짐작한 것만 표시합니다 (60건 이하)
    CASE WHEN ca.id IS NOT NULL AND COALESCE(TRIM(s.TRANSIT_A), '') = ''
         THEN '운송사추정' END,
    CASE WHEN COALESCE(TRIM(s.BLNUM), '') = '' THEN 'AWB번호없음' END,
    CASE WHEN COALESCE(TRIM(s.BLNUM), '') <> ''
          AND EXISTS (SELECT 1 FROM shipments_staging p
                       WHERE TRIM(COALESCE(p.BLNUM, '')) = TRIM(s.BLNUM)
                         AND p.staging_id < s.staging_id) THEN 'AWB중복' END,
    CASE WHEN UPPER(TRIM(COALESCE(s.INOUT, ''))) NOT IN
              ('1','2','I','IN','IMPORT','수입','O','OUT','E','EXPORT','수출')
         THEN 'INOUT미상' END,
    CASE WHEN cm.exact_id IS NULL AND cm.alias_id IS NOT NULL
         THEN '거래처별칭' END,
    CASE WHEN cm.exact_id IS NULL AND cm.alias_id IS NULL AND cm.company_id IS NOT NULL
         THEN '거래처공백차이' END,
    CASE WHEN d.saledate_d IS NULL AND d.dateship_d IS NULL AND d.regdate_d IS NULL
         THEN '전표일없음' END,
    CASE WHEN COALESCE(TRIM(s.AMOUNT), '') <> '' AND n.amount_d IS NULL
         THEN '금액변환실패' END
  ), '')
FROM shipments_staging s
-- 거래처 매칭 : 스테이징 1행당 정확히 1행. 동명 거래처가 있어도 행이 불어나지 않습니다
LEFT JOIN (
  -- 거래처 찾는 순서 : ① 상호 정확일치 → ② 사람이 확인한 별칭 → ③ 공백만 다른 상호
  --   ③ 은 딱 한 곳일 때만 붙입니다 ('H. P TEC' ↔ 'H.P TEC').
  --   둘 이상이면 어느 쪽인지 모르니 미확인으로 둡니다
  SELECT x.staging_id, x.exact_id, x.alias_id,
         COALESCE(x.exact_id, x.alias_id,
           (SELECT MIN(c.id) FROM companies c
             WHERE REPLACE(c.name_ko, ' ', '') = REPLACE(TRIM(x.company_raw), ' ', '')
               AND TRIM(COALESCE(x.company_raw, '')) <> ''
            HAVING COUNT(*) = 1)) AS company_id
  FROM (
    SELECT s2.staging_id, s2.COMPANY AS company_raw,
           (SELECT c.id FROM companies c
             WHERE c.name_ko = TRIM(s2.COMPANY)
             ORDER BY c.id LIMIT 1) AS exact_id,
           (SELECT c.id FROM migration_company_aliases al
              JOIN companies c ON c.company_code = al.company_code
             WHERE al.legacy_name = TRIM(s2.COMPANY) LIMIT 1) AS alias_id
    FROM shipments_staging s2
  ) x
) cm ON cm.staging_id = s.staging_id
-- 날짜 변환
JOIN (
  SELECT staging_id,
    CASE WHEN SALEDATE REGEXP '^[0-9]{4}[-/.][0-9]{1,2}[-/.][0-9]{1,2}'
         THEN STR_TO_DATE(REPLACE(REPLACE(LEFT(TRIM(SALEDATE),10),'/','-'),'.','-'), '%Y-%m-%d')
         WHEN SALEDATE REGEXP '^[0-9]{8}$' THEN STR_TO_DATE(SALEDATE, '%Y%m%d')
         ELSE NULL END AS saledate_d,
    CASE WHEN DATESHIP REGEXP '^[0-9]{4}[-/.][0-9]{1,2}[-/.][0-9]{1,2}'
         THEN STR_TO_DATE(REPLACE(REPLACE(LEFT(TRIM(DATESHIP),10),'/','-'),'.','-'), '%Y-%m-%d')
         WHEN DATESHIP REGEXP '^[0-9]{8}$' THEN STR_TO_DATE(DATESHIP, '%Y%m%d')
         ELSE NULL END AS dateship_d,
    STR_TO_DATE(LEFT(TRIM(REGDATE), 19), '%Y-%m-%d %H:%i:%s') AS regdate_d
  FROM shipments_staging
) d ON d.staging_id = s.staging_id
-- 숫자 변환 : 콤마·공백(할인율·유류할증은 % 까지) 제거 후 숫자면 캐스팅, 아니면 NULL.
-- 판정 조건과 캐스팅 대상이 **같은 식**이어야 합니다 (다르면 통과했는데 값이 틀어집니다)
JOIN (
  SELECT staging_id,
    CASE WHEN REPLACE(REPLACE(COALESCE(AMOUNT,''),',',''),' ','')
              REGEXP '^-?[0-9]+(\\.[0-9]+)?$'
         THEN CAST(REPLACE(REPLACE(COALESCE(AMOUNT,''),',',''),' ','') AS DECIMAL(15,2))
         ELSE NULL END AS amount_d,
    CASE WHEN REPLACE(REPLACE(COALESCE(TSAMOUNT,''),',',''),' ','')
              REGEXP '^-?[0-9]+(\\.[0-9]+)?$'
         THEN CAST(REPLACE(REPLACE(COALESCE(TSAMOUNT,''),',',''),' ','') AS DECIMAL(15,2))
         ELSE NULL END AS tsamount_d,
    CASE WHEN REPLACE(REPLACE(COALESCE(MAMOUNT,''),',',''),' ','')
              REGEXP '^-?[0-9]+(\\.[0-9]+)?$'
         THEN CAST(REPLACE(REPLACE(COALESCE(MAMOUNT,''),',',''),' ','') AS DECIMAL(15,2))
         ELSE NULL END AS mamount_d,
    CASE WHEN REPLACE(REPLACE(COALESCE(WEIGHT,''),',',''),' ','')
              REGEXP '^[0-9]+(\\.[0-9]+)?$'
         THEN CAST(REPLACE(REPLACE(COALESCE(WEIGHT,''),',',''),' ','') AS DECIMAL(10,2))
         ELSE NULL END AS weight_d,
    CASE WHEN REPLACE(REPLACE(COALESCE(PRICE,''),',',''),' ','')
              REGEXP '^[0-9]+(\\.[0-9]+)?$'
         THEN CAST(REPLACE(REPLACE(COALESCE(PRICE,''),',',''),' ','') AS DECIMAL(15,2))
         ELSE NULL END AS price_d,
    CASE WHEN REPLACE(REPLACE(REPLACE(COALESCE(DCYUL,''),',',''),' ',''),'%','')
              REGEXP '^[0-9]+(\\.[0-9]+)?$'
         THEN LEAST(CAST(REPLACE(REPLACE(REPLACE(COALESCE(DCYUL,''),',',''),' ',''),'%','')
                         AS DECIMAL(7,2)), 999.99)
         ELSE NULL END AS dcyul_d,
    CASE WHEN REPLACE(REPLACE(REPLACE(COALESCE(FUEL,''),',',''),' ',''),'%','')
              REGEXP '^[0-9]+(\\.[0-9]+)?$'
         THEN LEAST(CAST(REPLACE(REPLACE(REPLACE(COALESCE(FUEL,''),',',''),' ',''),'%','')
                         AS DECIMAL(7,2)), 999.99)
         ELSE NULL END AS fuel_d
  FROM shipments_staging
) n ON n.staging_id = s.staging_id
-- 운송사 : 단가표 이름에서 추론. TNT 는 FedEx 로 승계합니다
-- 운송사
--   ① TRANSIT_A 의 이름으로 찾습니다 — 위 5번에서 전부 운송사로 만들어 두었습니다.
--      49,360건 중 60건만 비어 있습니다.
--   ② TRANSIT_A 가 빈 60건만 AWB 번호 형태로 짐작합니다 (국제 표준으로 확실한 형태만).
--        영문2+숫자9+영문2 (EG160205830KR) → EMS   만국우편연합 S10 형식
--        1Z…                            → UPS
--        숫자 10자리 → DHL,  숫자 12자리 → FEDEX
--      짐작한 건은 migration_flag 에 '운송사추정' 이 붙습니다.
--   DANGA_NAME 은 실제 자료에서 전부 비어 있어 쓰지 않습니다 (2026-09-18 확인).
LEFT JOIN carriers ca ON ca.id = COALESCE(
  (SELECT c.id FROM carriers c
    WHERE COALESCE(TRIM(s.TRANSIT_A), '') <> ''
      AND (UPPER(c.code) = UPPER(TRIM(s.TRANSIT_A)) OR c.name = TRIM(s.TRANSIT_A))
    ORDER BY (UPPER(c.code) = UPPER(TRIM(s.TRANSIT_A))) DESC, c.id
    LIMIT 1),
  (SELECT c.id FROM carriers c
    WHERE COALESCE(TRIM(s.TRANSIT_A), '') = ''
      AND c.code =
        CASE WHEN UPPER(TRIM(COALESCE(s.BLNUM, ''))) REGEXP '^1Z'                        THEN 'UPS'
             WHEN UPPER(TRIM(COALESCE(s.BLNUM, ''))) REGEXP '^[A-Z]{2}[0-9]{9}[A-Z]{2}$' THEN 'EMS'
             WHEN UPPER(TRIM(COALESCE(s.BLNUM, ''))) REGEXP '^E[A-Z][0-9]{9}$'           THEN 'EMS'
             WHEN TRIM(COALESCE(s.BLNUM, '')) REGEXP '^[0-9]{10}$'                       THEN 'DHL'
             WHEN TRIM(COALESCE(s.BLNUM, '')) REGEXP '^[0-9]{12}$'                       THEN 'FEDEX'
        END
    LIMIT 1));

-- 미해석 컬럼 보관
INSERT INTO shipments_legacy_extra (
  shipment_id, colmoney, tsnum, tsweight, tsweight2, division, gubun,
  collect_yn, business, factory_a, transit_a,
  licence_yn, taxes_yn, bill_yn, deposit_yn, uptuser
)
SELECT sh.id,
       NULLIF(TRIM(s.COLMONEY),''), NULLIF(TRIM(s.TSNUM),''),
       NULLIF(TRIM(s.TSWEIGHT),''), NULLIF(TRIM(s.TSWEIGHT2),''),
       NULLIF(TRIM(s.DIVISION),''), NULLIF(TRIM(s.GUBUN),''),
       NULLIF(TRIM(s.COLLECT),''),  NULLIF(TRIM(s.BUSINESS),''),
       NULLIF(TRIM(s.FACTORY_A),''), NULLIF(TRIM(s.TRANSIT_A),''),
       NULLIF(TRIM(s.LICENCE),''),  NULLIF(TRIM(s.TAXES),''),
       NULLIF(TRIM(s.BILL),''),     NULLIF(TRIM(s.DEPOSIT),''),
       NULLIF(TRIM(s.UPTUSER),'')
FROM shipments_staging s
JOIN shipments sh ON sh.legacy_idx = CAST(NULLIF(TRIM(s.IDX), '') AS SIGNED);


-- ============================================================================
--  7. 비용항목 — 금액을 항목으로 풀어냅니다
--
--  세금구분 판정 : TAXES = 'Y' → 과세(10%), 그 외 → 영세(0%)
--  ★ 이 판정은 LICENCE / TAXES 실제 값을 확인한 뒤 확정해야 합니다.
--    지금은 컬럼 기본값이 'N' 이라는 사실에 기대어 TAXES='Y' 만 과세로 봅니다.
-- ============================================================================

-- 1행 : 주 운임 (AMOUNT). 금액이 없거나 변환 실패해도 0 으로 넣어 전표를 유지합니다
INSERT INTO shipment_charges
  (shipment_id, line_no, charge_type, item_name, qty, unit_price,
   supply_amount, tax_type, tax_rate, tax_amount, total_amount, remark)
SELECT sh.id, 1, 'AIR_FREIGHT',
       COALESCE(NULLIF(TRIM(s.DANGA_NAME), ''), '특송운임'),
       1, COALESCE(n.amount_d, 0), COALESCE(n.amount_d, 0),
       CASE WHEN UPPER(TRIM(COALESCE(s.TAXES,'N'))) = 'Y' THEN 'TAXABLE' ELSE 'ZERO' END,
       CASE WHEN UPPER(TRIM(COALESCE(s.TAXES,'N'))) = 'Y' THEN 10.00 ELSE 0.00 END,
       CASE WHEN UPPER(TRIM(COALESCE(s.TAXES,'N'))) = 'Y'
            THEN ROUND(COALESCE(n.amount_d, 0) * 0.1, 0) ELSE 0 END,
       COALESCE(n.amount_d, 0) +
       CASE WHEN UPPER(TRIM(COALESCE(s.TAXES,'N'))) = 'Y'
            THEN ROUND(COALESCE(n.amount_d, 0) * 0.1, 0) ELSE 0 END,
       CASE WHEN COALESCE(TRIM(s.AMOUNT),'') <> '' AND n.amount_d IS NULL
            THEN CONCAT('원본 금액 변환 실패: ', s.AMOUNT) ELSE '이관' END
FROM shipments_staging s
JOIN shipments sh ON sh.legacy_idx = CAST(NULLIF(TRIM(s.IDX), '') AS SIGNED)
JOIN (SELECT staging_id,
        CASE WHEN REPLACE(REPLACE(COALESCE(AMOUNT,''),',',''),' ','')
                  REGEXP '^-?[0-9]+(\\.[0-9]+)?$'
             THEN CAST(REPLACE(REPLACE(COALESCE(AMOUNT,''),',',''),' ','') AS DECIMAL(15,2))
             ELSE NULL END AS amount_d
      FROM shipments_staging) n ON n.staging_id = s.staging_id;

-- ★ 매출은 AMOUNT 한 줄뿐입니다.
--   예전에는 TSAMOUNT 를 '추가운임' 매출 2행으로 넣었으나, 2026-09-17 사용자 확인 결과
--   **TSAMOUNT 는 매입(운송사 지급액)** 입니다. 아래 8번에서 purchases 로 들어갑니다.
--   매출로 넣으면 68억이 이중계상됩니다. 되돌리지 마세요.


-- ============================================================================
--  8. 매입 — TSAMOUNT 를 purchases 로. 손익이 살아납니다
--
--  TSAMOUNT 가 매입이라는 것은 사용자가 확인해 준 사실입니다 (2026-09-17).
--  같은 TS* 묶음인 TSNUM 이 운송사 추적번호이고, TSAMOUNT < AMOUNT 인 행이 94%,
--  비율 중앙값 0.84 인 것과도 맞습니다.
--
--  MAMOUNT 는 49,360건 전부 비어 있어 쓰지 않습니다 (원본은 staging 에 그대로).
--
--  매입은 영세/과세 판정을 하지 않고 ZERO 로 넣습니다. 운송사 세금계산서를
--  받아봐야 알 수 있고, 추측해서 넣으면 부가세 신고가 틀어집니다.
-- ============================================================================

INSERT INTO purchases (
  business_entity_id, shipment_id, vendor_name, carrier_id,
  purchase_date, charge_type, supply_amount, tax_type, tax_amount, total_amount,
  recon_status, is_paid, remark, created_at
)
SELECT sh.business_entity_id, sh.id,
       COALESCE(ca.name, '(운송사 미확인)'), sh.carrier_id,
       sh.voucher_date, 'AIR_FREIGHT', n.tsamount_d, 'ZERO', 0, n.tsamount_d,
       'UNMATCHED', 0,
       CONCAT('기존 IS_SALES.TSAMOUNT 이관',
              CASE WHEN NULLIF(TRIM(s.TSNUM), '') IS NOT NULL
                   THEN CONCAT(' · 운송사번호 ', TRIM(s.TSNUM)) ELSE '' END),
       NOW()
FROM shipments_staging s
JOIN shipments sh ON sh.legacy_idx = CAST(NULLIF(TRIM(s.IDX), '') AS SIGNED)
LEFT JOIN carriers ca ON ca.id = sh.carrier_id
JOIN (SELECT staging_id,
        CASE WHEN REPLACE(REPLACE(COALESCE(TSAMOUNT,''),',',''),' ','')
                  REGEXP '^-?[0-9]+(\\.[0-9]+)?$'
             THEN CAST(REPLACE(REPLACE(COALESCE(TSAMOUNT,''),',',''),' ','') AS DECIMAL(15,2))
             ELSE NULL END AS tsamount_d
      FROM shipments_staging) n ON n.staging_id = s.staging_id
WHERE n.tsamount_d IS NOT NULL AND n.tsamount_d <> 0;

-- 변환에 실패한 TSAMOUNT 는 버리지 않고 남깁니다
INSERT INTO migration_errors (source_table, source_idx, column_name, raw_value, reason)
SELECT 'IS_SALES', TRIM(s.IDX), 'TSAMOUNT', s.TSAMOUNT, '매입금액 숫자 변환 실패'
FROM shipments_staging s
JOIN (SELECT staging_id,
        CASE WHEN REPLACE(REPLACE(COALESCE(TSAMOUNT,''),',',''),' ','')
                  REGEXP '^-?[0-9]+(\\.[0-9]+)?$'
             THEN CAST(REPLACE(REPLACE(COALESCE(TSAMOUNT,''),',',''),' ','') AS DECIMAL(15,2))
             ELSE NULL END AS tsamount_d
      FROM shipments_staging) n ON n.staging_id = s.staging_id
WHERE COALESCE(TRIM(s.TSAMOUNT), '') <> '' AND n.tsamount_d IS NULL;


-- ============================================================================
--  9. SHIPPER / CONSIGNEE / 품목 / 첨부
-- ============================================================================

INSERT INTO shipment_parties
  (shipment_id, party_type, company_name, address, phone, fax)
SELECT sh.id, 'SHIPPER', NULLIF(TRIM(s.COMPANY),''), NULLIF(TRIM(s.EADDRESS),''),
       NULLIF(TRIM(s.PHONE),''), NULLIF(TRIM(s.FAX),'')
FROM shipments_staging s
JOIN shipments sh ON sh.legacy_idx = CAST(NULLIF(TRIM(s.IDX), '') AS SIGNED)
WHERE COALESCE(TRIM(s.COMPANY),'') <> '' OR COALESCE(TRIM(s.EADDRESS),'') <> '';

INSERT INTO shipment_parties
  (shipment_id, party_type, company_name, contact_name, address, phone, fax)
SELECT sh.id, 'CONSIGNEE', NULLIF(TRIM(s.BUYERCODE),''), NULLIF(TRIM(s.CONTACTNAME),''),
       NULLIF(TRIM(s.BEADDRESS),''), NULLIF(TRIM(s.BPHONE),''), NULLIF(TRIM(s.BFAX),'')
FROM shipments_staging s
JOIN shipments sh ON sh.legacy_idx = CAST(NULLIF(TRIM(s.IDX), '') AS SIGNED)
WHERE COALESCE(TRIM(s.BUYERCODE),'') <> '' OR COALESCE(TRIM(s.BEADDRESS),'') <> '';

INSERT INTO shipment_items (shipment_id, line_no, item_name, qty, unit)
SELECT sh.id, 1, TRIM(s.DESCRIPTION),
       CASE WHEN TRIM(COALESCE(s.PCS,'')) REGEXP '^[0-9]+(\\.[0-9]+)?$'
            THEN CAST(s.PCS AS DECIMAL(12,2)) ELSE NULL END,
       NULLIF(TRIM(s.UNIT),'')
FROM shipments_staging s
JOIN shipments sh ON sh.legacy_idx = CAST(NULLIF(TRIM(s.IDX), '') AS SIGNED)
WHERE COALESCE(TRIM(s.DESCRIPTION),'') <> '';

-- 첨부파일 : 경로만 옮깁니다. 실제 파일은 별도 복사
INSERT INTO documents
  (business_entity_id, document_type_id, shipment_id, doc_date,
   title, original_name, stored_path, backup_status, created_at)
SELECT sh.business_entity_id, dt.id, sh.id, sh.voucher_date,
       TRIM(s.file1), TRIM(s.file1), TRIM(s.file1), 'PENDING', NOW()
FROM shipments_staging s
JOIN shipments sh ON sh.legacy_idx = CAST(NULLIF(TRIM(s.IDX), '') AS SIGNED)
JOIN document_types dt ON dt.code = 'AWB'
WHERE COALESCE(TRIM(s.file1),'') <> '';


-- ============================================================================
--  10. 변환 실패 기록 — 값을 버리지 않습니다
-- ============================================================================

INSERT INTO migration_errors (source_table, source_idx, column_name, raw_value, reason)
SELECT 'IS_SALES', s.IDX, v.col, v.val, '숫자로 변환할 수 없음'
FROM shipments_staging s
JOIN (
  SELECT staging_id, 'AMOUNT'   AS col, AMOUNT   AS val FROM shipments_staging UNION ALL
  SELECT staging_id, 'TSAMOUNT',      TSAMOUNT FROM shipments_staging UNION ALL
  SELECT staging_id, 'MAMOUNT',       MAMOUNT  FROM shipments_staging UNION ALL
  SELECT staging_id, 'WEIGHT',        WEIGHT   FROM shipments_staging UNION ALL
  SELECT staging_id, 'PRICE',         PRICE    FROM shipments_staging
) v ON v.staging_id = s.staging_id
WHERE COALESCE(TRIM(v.val), '') <> ''
  AND REPLACE(REPLACE(v.val, ',', ''), ' ', '') NOT REGEXP '^-?[0-9]+(\\.[0-9]+)?$';

INSERT INTO migration_errors (source_table, source_idx, column_name, raw_value, reason)
SELECT 'IS_SALES', IDX, 'SALEDATE', SALEDATE, '날짜로 변환할 수 없음'
FROM shipments_staging
WHERE COALESCE(TRIM(SALEDATE), '') <> ''
  AND SALEDATE NOT REGEXP '^[0-9]{4}[-/.][0-9]{1,2}[-/.][0-9]{1,2}'
  AND SALEDATE NOT REGEXP '^[0-9]{8}$';


-- ============================================================================
--  11. 검증 — ★ 금액 총합 대조가 핵심입니다
--
--  기존 시스템의 총합은 이 쿼리로 얻습니다 (MSSQL 에서 실행)
--      SELECT SUM(CAST(REPLACE(AMOUNT, ',', '') AS FLOAT)) FROM IS_SALES;
--  그 값과 아래 (3) 이 일치해야 합니다.
-- ============================================================================

-- (1) 건수 일치 : 차이가 0 이어야 합니다
SELECT (SELECT COUNT(*) FROM shipments_staging) AS 원본,
       (SELECT COUNT(*) FROM shipments)         AS 신규,
       (SELECT COUNT(*) FROM shipments_staging)
     - (SELECT COUNT(*) FROM shipments)         AS 차이_0이어야함;

-- (2) 누락된 원본 전표 : 반드시 0 건
SELECT s.staging_id, s.IDX, s.BLNUM, s.COMPANY, s.AMOUNT
FROM shipments_staging s
LEFT JOIN shipments sh ON sh.legacy_idx = CAST(NULLIF(TRIM(s.IDX), '') AS SIGNED)
WHERE sh.id IS NULL;

-- (3) ★ 매출 총합 대조 : 원본 AMOUNT 합계 = 신규 1행 비용항목 합계
SELECT
  (SELECT SUM(CAST(REPLACE(REPLACE(AMOUNT,',',''),' ','') AS DECIMAL(18,2)))
     FROM shipments_staging
    WHERE REPLACE(REPLACE(COALESCE(AMOUNT,''),',',''),' ','')
          REGEXP '^-?[0-9]+(\\.[0-9]+)?$')                     AS 원본_AMOUNT합,
  (SELECT SUM(supply_amount) FROM shipment_charges WHERE line_no = 1) AS 신규_주운임합,
  (SELECT SUM(CAST(REPLACE(REPLACE(AMOUNT,',',''),' ','') AS DECIMAL(18,2)))
     FROM shipments_staging
    WHERE REPLACE(REPLACE(COALESCE(AMOUNT,''),',',''),' ','')
          REGEXP '^-?[0-9]+(\\.[0-9]+)?$')
  - (SELECT SUM(supply_amount) FROM shipment_charges WHERE line_no = 1) AS 차이_0이어야함;

-- (4) 매입 총합 대조 — 원본 TSAMOUNT 합과 같아야 합니다
--     2026-09-17 실측 기준값 : 6,818,962,184 (39,524건)
SELECT
  (SELECT SUM(CAST(REPLACE(REPLACE(COALESCE(TSAMOUNT,'0'),',',''),' ','') AS DECIMAL(18,2)))
     FROM shipments_staging
    WHERE REPLACE(REPLACE(COALESCE(TSAMOUNT,''),',',''),' ','')
          REGEXP '^-?[0-9]+(\\.[0-9]+)?$')                            AS 원본_TSAMOUNT합,
  (SELECT SUM(supply_amount) FROM purchases)                          AS 신규_매입합,
  (SELECT COUNT(*) FROM shipment_charges WHERE line_no = 2)           AS 매출2행_0이어야함;
--     ^ 매출은 AMOUNT 한 줄뿐입니다. 여기가 0 이 아니면 TSAMOUNT 가 매출로 새어든 것입니다

-- (5) 보정이 필요한 전표 — 사람이 화면에서 처리할 목록
SELECT migration_flag, COUNT(*) AS 건수
FROM shipments WHERE migration_flag IS NOT NULL
GROUP BY migration_flag ORDER BY 건수 DESC;

-- (6) 거래처 미확인 전표의 상호명 — 어떤 이름들이 매칭 실패했는지
SELECT s.COMPANY AS 원본상호, COUNT(*) AS 건수,
       SUM(CAST(REPLACE(REPLACE(COALESCE(s.AMOUNT,'0'),',',''),' ','') AS DECIMAL(18,2))) AS 금액합
FROM shipments s2
JOIN shipments_staging s ON s.IDX = CAST(s2.legacy_idx AS CHAR)
JOIN companies cu ON cu.id = s2.company_id AND cu.company_code = 'UNMATCHED'
GROUP BY s.COMPANY ORDER BY 건수 DESC;

-- (7) 원본 AWB 중복 — 뒤에 나온 중복은 '-D<번호>' 가 붙어 들어갔습니다 (실패하지 않습니다)
SELECT TRIM(BLNUM) AS awb, COUNT(*) c
FROM shipments_staging WHERE COALESCE(TRIM(BLNUM),'') <> ''
GROUP BY TRIM(BLNUM) HAVING c > 1 ORDER BY c DESC;

-- (8) INOUT 값 분포 — 수출/수입 사상이 맞는지 눈으로 확인
SELECT `INOUT`, COUNT(*) AS 건수 FROM shipments_staging GROUP BY `INOUT` ORDER BY 건수 DESC;

-- (9) ★ LICENCE / TAXES 값 분포 — 세금구분 판정의 근거. 반드시 확인하세요
SELECT LICENCE, TAXES, COUNT(*) AS 건수,
       SUM(CAST(REPLACE(REPLACE(COALESCE(AMOUNT,'0'),',',''),' ','') AS DECIMAL(18,2))) AS 금액합
FROM shipments_staging GROUP BY LICENCE, TAXES ORDER BY 건수 DESC;

-- (10) DANGA_NAME 분포 — 운송사 추론이 제대로 됐는지
SELECT DANGA_NAME, COUNT(*) AS 건수 FROM shipments_staging
GROUP BY DANGA_NAME ORDER BY 건수 DESC LIMIT 50;

-- (11) 변환 실패 요약
SELECT source_table, column_name, COUNT(*) AS 건수
FROM migration_errors GROUP BY source_table, column_name ORDER BY 건수 DESC;

-- (12) 연도별 매출 — 기존 매출통계 화면과 눈으로 대조하는 용도
SELECT YEAR(sh.voucher_date) AS 연도, COUNT(*) AS 전표수,
       SUM(t.supply_total) AS 공급가액, SUM(t.tax_total) AS VAT
FROM shipments sh JOIN v_shipment_totals t ON t.shipment_id = sh.id
GROUP BY YEAR(sh.voucher_date) ORDER BY 연도;


-- ============================================================================
--  12. 앱 DB 계정 권한 — 전표도 물리삭제를 막습니다
--
--    GRANT SELECT, INSERT, UPDATE ON goodpost.shipments         TO 'gp_app'@'localhost';
--    GRANT SELECT, INSERT, UPDATE, DELETE
--                                 ON goodpost.shipment_charges  TO 'gp_app'@'localhost';
--        ^ 비용항목은 작성 중 행 삭제가 필요하므로 DELETE 를 허용하되,
--          트리거가 삭제 직전 행을 shipments_history 에 남깁니다
--    GRANT SELECT, INSERT         ON goodpost.shipments_history  TO 'gp_app'@'localhost';
--    GRANT SELECT                 ON goodpost.shipments_staging  TO 'gp_app'@'localhost';
--    -- shipments 에는 DELETE 를 주지 않습니다. 취소는 status='CANCELLED' 로 합니다
-- ============================================================================

-- ============================================================================
--  13. 확인 상태 — 2026-09-17 실제 CSV(49,360건) 점검 결과
--
--   ✔ TSAMOUNT = 매입      사용자 확인 완료. 8번에서 purchases 로 들어갑니다.
--                          매출로 넣으면 68억이 이중계상됩니다
--   ✔ MAMOUNT             49,360건 전부 비어 있음. 쓰지 않습니다
--   ✔ BLNUM(AWB) 중복      0건. UNIQUE 제약에 걸릴 것이 없습니다
--   ✔ LICENCE / TAXES     TAXES='Y' 652건(1.3%)만 과세, 나머지 영세율.
--                          항공특송 수출이 주력인 것과 맞습니다
--   ✔ BUYERCODE           100% 비어 있음 → COMPANY(상호)로 연결하는 현재 방식이 맞습니다
--
--   □ COMPANY 상호명 매칭률  → 위 (6). IS_SALES 쪽 서로 다른 상호 844개 대
--                             CUSTOMERS 885건. CUSTOMERS.csv 를 받으면 사전 계산합니다
--   □ INOUT / DIVISION / GUBUN 의 뜻 → 몰라도 이관은 진행됩니다.
--                             원본값은 shipments_legacy_extra 에 그대로 남습니다
--
--  ★ 이관 후 대조 기준 (2026-09-17 원본 실측)
--      전표         49,360 건
--      AMOUNT 합계  12,868,560,851 원   → 매출
--      TSAMOUNT 합계 6,818,962,184 원   → 매입 (39,524건에만 존재)
-- ============================================================================
