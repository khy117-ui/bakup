-- ============================================================================
--  거래처 — [B] 데이터 이관
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
--      DELETE FROM company_carrier_terms;
--      DELETE FROM company_files;
--      DELETE FROM company_contacts;
--      DELETE FROM companies_legacy_extra;
--      DELETE FROM companies;
--      DELETE FROM companies_history;
--      DELETE FROM migration_errors WHERE source_table = 'CUSTOMERS';
-- ============================================================================

SET NAMES utf8mb4;

-- ============================================================================
--  4. 거래처 이관 — 한 건도 빠지지 않게
--
--  DEL 값이 무엇이든 **전 건을 살아있는 상태(deleted_at IS NULL)로** 넣습니다.
--  DEL 원본은 companies_legacy_extra.legacy_del 에 보관합니다.
--  삭제 판정은 6번에서 별도로, 되돌릴 수 있는 방식으로 적용합니다.
--
--  homepage 는 CUSTOMERS 에 대응 컬럼이 없습니다 (신규 입력용).
-- ============================================================================

INSERT INTO companies (
  company_code, name_ko, name_en, representative,
  business_number, corp_number, business_type, business_item,
  phone, fax, email, address_ko, address_en,
  joined_on, sales_team, sales_rep,
  payment_method, payment_terms, billing_day, memo,
  created_at, deleted_at, legacy_idx
)
SELECT
  -- CUTCODE 가 비었거나 **중복이면** 코드를 나눠 줍니다. 행을 버리지 않습니다.
  -- company_code 는 UNIQUE 이므로 중복을 그대로 넣으면 INSERT 전체가 실패하고
  -- 거래처가 한 건도 들어가지 않습니다.
  --
  -- ★ 옛 시스템은 한 코드를 **서로 다른 회사**가 나눠 썼습니다 (15개 코드 · 32개 회사).
  --   먼저 등록된 회사(IDX 가 작은 쪽)가 원래 코드를 갖고, 다음 회사부터
  --   '-D2', '-D3' 을 붙입니다.   예) 01-A101 · 01-A101-D2 · 01-A101-D3
  --   사용자 요청 (2026-09-18) : "겹치는 곳은 임의로 코드를 나눠서 등록"
  CASE
    WHEN COALESCE(TRIM(s.CUTCODE), '') = ''
         THEN CONCAT('LEGACY-', s.staging_id)
    WHEN EXISTS (SELECT 1 FROM companies_staging p
                  WHERE TRIM(COALESCE(p.CUTCODE, '')) = TRIM(s.CUTCODE)
                    AND p.staging_id < s.staging_id)
         THEN CONCAT(TRIM(s.CUTCODE), '-D',
                     1 + (SELECT COUNT(*) FROM companies_staging p
                           WHERE TRIM(COALESCE(p.CUTCODE, '')) = TRIM(s.CUTCODE)
                             AND p.staging_id < s.staging_id))
    ELSE TRIM(s.CUTCODE)
  END,
  -- 상호가 비어도 행을 버리지 않습니다. 사람이 나중에 채울 수 있게 표시만 합니다
  CASE WHEN COALESCE(TRIM(s.COMPANY), '') = ''
       THEN CONCAT('(상호없음 IDX=', COALESCE(s.IDX, '?'), ')')
       ELSE TRIM(s.COMPANY) END,
  NULLIF(TRIM(s.ECOMPANY), ''),
  NULLIF(TRIM(s.PRESIDENT), ''),
  NULLIF(TRIM(s.BNUMBER), ''),
  NULLIF(TRIM(s.JUMBER), ''),
  NULLIF(TRIM(s.CATEGORY), ''),            -- 업태 ('도소매' · '서비스' · '제조업'). 원본도 legacy_category 에 남김
  -- 종목(business_item) 은 옛 자료에 없습니다. 비워 둡니다.
  -- ★ 예전에는 BUSINESS 를 여기 넣었는데, 실제 CSV 를 보니 BUSINESS 는 종목이 아니라
  --   **우리 회사 영업담당자**였습니다 (김화연 199 · 이창호 165 · 전종학 144 …).
  --   종목은 세금계산서에 인쇄되는 칸이라 사람 이름이 들어가면 안 됩니다. (2026-09-18)
  NULL,
  NULLIF(TRIM(s.PHONE), ''),
  NULLIF(TRIM(s.FAX), ''),
  NULLIF(TRIM(s.EMAIL), ''),
  NULLIF(TRIM(s.ADDRESS), ''),
  NULLIF(TRIM(s.EADDRESS), ''),
  -- 날짜 변환 실패는 NULL 로 두고 원본은 staging 에 남습니다. 행을 버리지 않습니다
  CASE WHEN s.SINCEDATE REGEXP '^[0-9]{4}[-/.][0-9]{1,2}[-/.][0-9]{1,2}'
       THEN STR_TO_DATE(REPLACE(REPLACE(LEFT(TRIM(s.SINCEDATE), 10), '/', '-'), '.', '-'), '%Y-%m-%d')
       WHEN s.SINCEDATE REGEXP '^[0-9]{8}$'
       THEN STR_TO_DATE(s.SINCEDATE, '%Y%m%d')
       ELSE NULL END,
  NULLIF(TRIM(s.TEAM), ''),                -- 팀코드. CUTCODE 앞 두 자리와 821/885 일치
  -- 영업담당자 = BUSINESS. SPOT 은 담당자가 아니라 지점입니다 ('서울지점' 324 · '서울본사' 168).
  -- SPOT 원본은 companies_staging 에 그대로 남습니다
  NULLIF(TRIM(s.BUSINESS), ''),
  NULLIF(TRIM(s.PAYMETHOD), ''),
  NULLIF(TRIM(s.PAYMENTS), ''),
  NULLIF(TRIM(s.PAYREGDATE), ''),
  NULLIF(TRIM(s.CONTENTS), ''),
  -- REGDATE 가 실제 등록일시입니다. 살릴 수 있으면 살립니다
  COALESCE(STR_TO_DATE(LEFT(TRIM(s.REGDATE), 19), '%Y-%m-%d %H:%i:%s'), NOW()),
  NULL,                          -- deleted_at : 전 건 활성. DEL 판정은 6번에서
  CAST(NULLIF(TRIM(s.IDX), '') AS SIGNED)
FROM companies_staging s;

-- 미해석 컬럼 + DEL 원본 보관
INSERT INTO companies_legacy_extra (
  company_id, eventsd, bill, publication, saledate,
  c_fuel, c_fuel2, c_fuel3, legacy_del, legacy_category, legacy_business
)
SELECT c.id,
       NULLIF(TRIM(s.EVENTSD), ''), NULLIF(TRIM(s.BILL), ''),
       NULLIF(TRIM(s.PUBLICATION), ''), NULLIF(TRIM(s.SALEDATE), ''),
       NULLIF(TRIM(s.C_FUEL), ''), NULLIF(TRIM(s.C_FUEL2), ''),
       NULLIF(TRIM(s.C_FUEL3), ''), s.`DEL`,
       NULLIF(TRIM(s.CATEGORY), ''), NULLIF(TRIM(s.BUSINESS), '')
FROM companies_staging s
JOIN companies c ON c.legacy_idx = CAST(NULLIF(TRIM(s.IDX), '') AS SIGNED);


-- ============================================================================
--  5. 담당자 · 할인율 · 첨부파일
--
--  ★ 원본에 담당자 **이름 컬럼이 없습니다.** RPHONE / RPHONE2 전화번호 2개뿐입니다.
--    이름은 '담당자1' / '담당자2' 로 넣고 memo 에 출처를 적습니다.
--    신규 시스템에서 사람이 실제 이름을 채우면 됩니다.
-- ============================================================================

INSERT INTO company_contacts (company_id, name, phone, contact_type, is_primary, memo)
SELECT c.id, '담당자1', TRIM(s.RPHONE), 'GENERAL', 1, '기존 CUSTOMERS.RPHONE 이관 — 이름 확인 필요'
FROM companies_staging s
JOIN companies c ON c.legacy_idx = CAST(NULLIF(TRIM(s.IDX), '') AS SIGNED)
WHERE COALESCE(TRIM(s.RPHONE), '') <> '';

INSERT INTO company_contacts (company_id, name, phone, contact_type, is_primary, memo)
SELECT c.id, '담당자2', TRIM(s.RPHONE2), 'GENERAL', 0, '기존 CUSTOMERS.RPHONE2 이관 — 이름 확인 필요'
FROM companies_staging s
JOIN companies c ON c.legacy_idx = CAST(NULLIF(TRIM(s.IDX), '') AS SIGNED)
WHERE COALESCE(TRIM(s.RPHONE2), '') <> '';

-- 첨부파일 — 경로만 옮깁니다. 실제 파일은 별도로 복사해야 합니다
INSERT INTO company_files (company_id, file_type, original_name, stored_path, created_at)
SELECT c.id, 'OTHER', TRIM(s.file1), TRIM(s.file1), NOW()
FROM companies_staging s
JOIN companies c ON c.legacy_idx = CAST(NULLIF(TRIM(s.IDX), '') AS SIGNED)
WHERE COALESCE(TRIM(s.file1), '') <> '';

-- 할인율 : 컬럼 6개 → 행으로. TNT 계열(DCTNT / IDC_TNT)은 FedEx 전환으로 제외합니다
-- carriers 가 먼저 적재돼 있어야 합니다 (12_seed.sql)
INSERT INTO company_carrier_terms
  (company_id, carrier_id, trade_type, discount_rate, effective_from, memo)
SELECT c.id, ca.id, m.trade_type,
       CAST(REPLACE(REPLACE(REPLACE(v.val, ',', ''), '%', ''), ' ', '') AS DECIMAL(5,2)),
       '2000-01-01',
       CONCAT('기존 CUSTOMERS.', m.src, ' 이관')
FROM companies_staging s
JOIN companies c ON c.legacy_idx = CAST(NULLIF(TRIM(s.IDX), '') AS SIGNED)
JOIN (
      SELECT 'DCDHL'   AS src, 'DHL'   AS carrier_code, 'ALL'    AS trade_type UNION ALL
      SELECT 'DCUPS',        'UPS',          'ALL'    UNION ALL
      SELECT 'DCEMS',        'EMS',          'ALL'    UNION ALL
      SELECT 'IDC_DHL',      'DHL',          'IMPORT'
     ) m ON 1 = 1
JOIN carriers ca ON ca.code = m.carrier_code
JOIN (
      SELECT staging_id, 'DCDHL'   AS src, DCDHL   AS val FROM companies_staging UNION ALL
      SELECT staging_id, 'DCUPS',        DCUPS   FROM companies_staging UNION ALL
      SELECT staging_id, 'DCEMS',        DCEMS   FROM companies_staging UNION ALL
      SELECT staging_id, 'IDC_DHL',      IDC_DHL FROM companies_staging
     ) v ON v.staging_id = s.staging_id AND v.src = m.src
WHERE COALESCE(TRIM(v.val), '') <> ''
  AND REPLACE(REPLACE(REPLACE(v.val, ',', ''), '%', ''), ' ', '') REGEXP '^[0-9]+(\\.[0-9]+)?$'
  -- discount_rate 는 DECIMAL(5,2) 로 999.99 까지입니다. 할인율은 % 이므로 100 을
  -- 넘는 값은 데이터 오류입니다. 넣지 않고 아래에서 migration_errors 에 남깁니다
  AND CAST(REPLACE(REPLACE(REPLACE(v.val, ',', ''), '%', ''), ' ', '') AS DECIMAL(10,2)) <= 100;

-- 숫자로 바꿀 수 없던 할인율은 버리지 않고 기록합니다
INSERT INTO migration_errors (source_table, source_idx, column_name, raw_value, reason)
SELECT 'CUSTOMERS', s.IDX, v.src, v.val, '할인율이 숫자가 아니거나 100% 를 초과함'
FROM companies_staging s
JOIN (
      SELECT staging_id, 'DCDHL'   AS src, DCDHL   AS val FROM companies_staging UNION ALL
      SELECT staging_id, 'DCUPS',        DCUPS   FROM companies_staging UNION ALL
      SELECT staging_id, 'DCEMS',        DCEMS   FROM companies_staging UNION ALL
      SELECT staging_id, 'IDC_DHL',      IDC_DHL FROM companies_staging
     ) v ON v.staging_id = s.staging_id
WHERE COALESCE(TRIM(v.val), '') <> ''
  AND (REPLACE(REPLACE(REPLACE(v.val, ',', ''), '%', ''), ' ', '') NOT REGEXP '^[0-9]+(\\.[0-9]+)?$'
       OR CAST(REPLACE(REPLACE(REPLACE(v.val, ',', ''), '%', ''), ' ', '')
               AS DECIMAL(10,2)) > 100);


-- ============================================================================
--  6. DEL 판정 — 확인 후에, 되돌릴 수 있게
--
--  먼저 어느 쪽인지 확정합니다.
--      MSSQL : SELECT DEL, COUNT(*) FROM CUSTOMERS GROUP BY DEL;
--      MySQL : SELECT legacy_del, COUNT(*) FROM companies_legacy_extra GROUP BY legacy_del;
--
--  아래 둘 중 하나만 실행합니다. 둘 다 [되돌리기] 가 붙어 있습니다.
-- ============================================================================

-- ★ 확정 (2026-09-18, 실제 CUSTOMERS.csv 885건)
--     DEL = 'Y'  884건  → 사용중
--     DEL = 'N'    1건  → IDX 250 '테스트' (CUTCODE 01-B001234)
--   'Y' 를 삭제로 읽었다면 거래처 884곳이 전부 사라졌을 것입니다.
--   판정을 이관 시점까지 미뤄 둔 이유가 이것이었습니다.
--
--   [경우 B] 를 실행합니다 — 'N' 1건(테스트 계정)만 숨깁니다.

-- [경우 A] 'Y' = 삭제됨 — **쓰지 않습니다.** 884곳이 사라집니다
-- UPDATE companies c
--   JOIN companies_legacy_extra e ON e.company_id = c.id
--    SET c.deleted_at = '2000-01-01 00:00:00'
--  WHERE e.legacy_del = 'Y' AND c.deleted_at IS NULL;

-- [경우 B] 'N' = 삭제됨 — 실행합니다
UPDATE companies c
  JOIN companies_legacy_extra e ON e.company_id = c.id
   SET c.deleted_at = '2000-01-01 00:00:00'   -- 이관에 의한 숨김임을 알 수 있는 고정값
 WHERE e.legacy_del = 'N' AND c.deleted_at IS NULL;

-- [되돌리기] 고정값으로 넣었기 때문에 정확히 되돌아갑니다
-- UPDATE companies SET deleted_at = NULL WHERE deleted_at = '2000-01-01 00:00:00';

-- 판정을 미뤄도 업무에 지장이 없습니다. 목록 화면은 deleted_at IS NULL 로 걸리고,
-- 그때까지는 전 건이 보입니다. 안 보이는 것보다 더 보이는 쪽이 안전합니다.


-- ============================================================================
--  7. 검증 — 이관 직후 반드시 실행
--  (1)(2)(3) 이 0 이 아니면 이관을 되돌리고 원인을 찾습니다
-- ============================================================================

-- (1) 건수 일치
SELECT (SELECT COUNT(*) FROM companies_staging)        AS 원본,
       (SELECT COUNT(*) FROM companies)                AS 신규,
       (SELECT COUNT(*) FROM companies_staging)
     - (SELECT COUNT(*) FROM companies)                AS 차이_0이어야함;

-- (2) 누락된 원본 행 : 반드시 0 건
SELECT s.staging_id, s.IDX, s.CUTCODE, s.COMPANY
FROM companies_staging s
LEFT JOIN companies c ON c.legacy_idx = CAST(NULLIF(TRIM(s.IDX), '') AS SIGNED)
WHERE c.id IS NULL;

-- (3) 미해석 컬럼 보관 누락 : 반드시 0 건
SELECT COUNT(*) AS 보관누락_0이어야함
FROM companies c
LEFT JOIN companies_legacy_extra e ON e.company_id = c.id
WHERE e.company_id IS NULL;

-- (4) 상호 대조 : 임시상호 부여분만 나와야 함
SELECT s.IDX, s.COMPANY AS 원본상호, c.name_ko AS 신규상호
FROM companies_staging s
JOIN companies c ON c.legacy_idx = CAST(NULLIF(TRIM(s.IDX), '') AS SIGNED)
WHERE TRIM(COALESCE(s.COMPANY, '')) <> c.name_ko
  AND c.name_ko NOT LIKE '(상호없음%';

-- (5) 사람이 보정해야 하는 목록 — 임시코드 / 중복코드 / 임시상호
SELECT id, company_code, name_ko, legacy_idx,
       CASE WHEN company_code LIKE 'LEGACY-%' THEN '코드없음'
            WHEN company_code LIKE '%-D%'     THEN '코드중복'
            ELSE '상호없음' END AS 사유
FROM companies
WHERE company_code LIKE 'LEGACY-%'
   OR company_code REGEXP '-D[0-9]+$'
   OR name_ko LIKE '(상호없음%';

-- (6) CUTCODE 중복이 있었는지 — 신규는 UNIQUE 라서 이관이 실패했을 수 있습니다
SELECT TRIM(CUTCODE) AS cutcode, COUNT(*) c
FROM companies_staging
WHERE COALESCE(TRIM(CUTCODE), '') <> ''
GROUP BY TRIM(CUTCODE) HAVING c > 1;

-- (7) 사업자번호 중복 — 같은 업체가 두 번 등록돼 있는지
SELECT business_number, COUNT(*) c, GROUP_CONCAT(name_ko SEPARATOR ' | ') AS 업체들
FROM companies
WHERE COALESCE(business_number, '') <> ''
GROUP BY business_number HAVING c > 1;

-- (8) DEL 분포 — 판정 전에 눈으로 봅니다
SELECT legacy_del, COUNT(*) AS 건수 FROM companies_legacy_extra
GROUP BY legacy_del ORDER BY 건수 DESC;

-- (9) 날짜 변환 실패 — 값은 staging 에 남아 있습니다
SELECT COUNT(*) AS 가입일변환실패
FROM companies_staging s
JOIN companies c ON c.legacy_idx = CAST(NULLIF(TRIM(s.IDX), '') AS SIGNED)
WHERE COALESCE(TRIM(s.SINCEDATE), '') <> '' AND c.joined_on IS NULL;

-- (10) 변환 실패 기록 요약
SELECT source_table, column_name, COUNT(*) AS 건수
FROM migration_errors GROUP BY source_table, column_name ORDER BY 건수 DESC;


-- ============================================================================
--  8. 앱 DB 계정 권한 — 물리삭제를 구조적으로 막습니다
--
--  아래를 실제 계정명·호스트로 바꿔 관리자 계정에서 실행합니다.
--  비밀번호는 이 파일에 적지 않습니다.
--
--    GRANT SELECT, INSERT, UPDATE ON goodpost.companies         TO 'gp_app'@'localhost';
--    GRANT SELECT, INSERT         ON goodpost.companies_history TO 'gp_app'@'localhost';
--    GRANT SELECT                 ON goodpost.companies_staging TO 'gp_app'@'localhost';
--    -- DELETE 권한을 아예 주지 않습니다. 코드에 DELETE 가 섞여도 DB 가 거부합니다
--
--  이관 계정(gp_migrate)만 companies_staging 에 INSERT 할 수 있게 하고,
--  이관이 끝나면 그 계정을 잠급니다.
-- ============================================================================

-- ============================================================================
--  9. 백업
--
--  이관 전 (원본 MSSQL)  : 백업 파일을 별도 매체에 1부 더 복사
--  staging 적재 후       : mysqldump goodpost companies_staging > 거래처원본_YYYYMMDD.sql
--  이관 후               : mysqldump goodpost > 전체_YYYYMMDD.sql
--  운영 시작 후          : 일 1회 자동 덤프 + NAS 보관, 주 1회 오프사이트
--
--  companies_staging 덤프 한 개만 있으면 거래처는 언제든 처음부터 다시 이관할 수 있습니다.
-- ============================================================================
