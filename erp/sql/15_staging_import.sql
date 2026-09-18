-- ============================================================================
--  실제 데이터 가져오기 — 기존 MSSQL → staging → 13·14번 이관
--
--  순서
--    1) MSSQL 에서 CSV 로 뽑기            (아래 [A] 쿼리)
--    2) 이 파일의 [B] 실행 — raw_json 을 잠시 NULL 허용으로 바꿉니다
--    3) phpMyAdmin 에서 CSV import        (아래 [C] 설정)
--    4) 이 파일의 [D] 실행 — raw_json · 지문 채우고 NOT NULL 복구
--    5) 13b_companies_migrate.sql → 14b_shipments_migrate.sql
--
--  raw_json 은 "제가 컬럼을 놓쳤어도 원본 값이 남게" 하는 안전장치입니다.
--  CSV import 로는 채울 수 없어서 [B]/[D] 로 나눴습니다.
-- ============================================================================


-- ============================================================================
--  [A] MSSQL 에서 실행 — CSV 로 내보낼 쿼리
--      myLittleAdmin 이나 SSMS 에서 돌려 결과를 CSV 로 저장합니다.
--      ★ 컬럼 순서를 바꾸지 마세요. staging 테이블 순서와 맞춰져 있습니다.
--      ★ 헤더 포함으로 저장하세요.
-- ============================================================================
/*
-- 거래처 (40컬럼)
SELECT IDX, CUTCODE, COMPANY, ECOMPANY, PRESIDENT, BNUMBER, JUMBER, CATEGORY,
       PHONE, EVENTSD, FAX, ADDRESS, EADDRESS, SINCEDATE, TEAM, SPOT, BUSINESS,
       CONTENTS, PAYMENTS, BILL, EMAIL, RPHONE, RPHONE2, PAYMETHOD, PAYREGDATE,
       PUBLICATION, CONVERT(varchar(19), REGDATE, 120) AS REGDATE,
       DCDHL, DCTNT, DCUPS, DCEMS, IDC_DHL, IDC_TNT,
       C_FUEL, C_FUEL2, C_FUEL3, SALEDATE, file1, filenum, DEL
FROM CUSTOMERS
ORDER BY IDX;

-- 매출전표 (51컬럼)
SELECT IDX, COMPANY, EADDRESS, PHONE, FAX, BUYERCODE, CONTACTNAME, BEADDRESS,
       BPHONE, BFAX, ARRIVAL_N, FACTORY_A, TRANSIT_A, WEIGHT, AMOUNT, FUEL,
       DANGA_NUM, DANGA_NAME, PRICE, DCYUL, COLMONEY, TSNUM, TSWEIGHT, TSAMOUNT,
       DESCRIPTION, PCS, UNIT, MAMOUNT, DATESHIP, CONTENTS, SPOT, COMM,
       CONVERT(varchar(19), REGDATE, 120) AS REGDATE,
       BLNUM, INOUT, COLLECT, SALEDATE, DIVISION, BUSINESS, TEAM, LICENCE,
       BILL, TAXES, DEPOSIT, file1, filenum, remark, GUBUN, TSWEIGHT2,
       CONVERT(varchar(19), UPTDATE, 120) AS UPTDATE, UPTUSER
FROM IS_SALES
ORDER BY IDX;

-- 먼저 건수를 세두면 이관 후 대조할 수 있습니다
SELECT COUNT(*) AS 거래처건수 FROM CUSTOMERS;
SELECT COUNT(*) AS 전표건수   FROM IS_SALES;
*/


-- ============================================================================
--  [B] import 하기 전에 실행 — raw_json 을 잠시 NULL 허용으로
-- ============================================================================

ALTER TABLE companies_staging MODIFY raw_json JSON NULL;
ALTER TABLE shipments_staging MODIFY raw_json JSON NULL;


-- ============================================================================
--  [C] phpMyAdmin CSV import 설정
--
--    대상 테이블   companies_staging  /  shipments_staging
--    형식          CSV
--    컬럼 구분     ,        묶음 문자  "        이스케이프  \
--    줄 구분       auto
--    [v] 파일의 첫 줄에 테이블 컬럼 이름이 있음   ← 반드시 체크
--    [v] 부분 import 허용 (큰 파일이면)
--
--    "컬럼 이름" 칸에는 아래를 그대로 붙여넣으세요.
--    staging 에는 staging_id·raw_json 같은 추가 컬럼이 있어서, 이름을 지정하지
--    않으면 순서가 밀립니다.
--
--    거래처용:
--      IDX,CUTCODE,COMPANY,ECOMPANY,PRESIDENT,BNUMBER,JUMBER,CATEGORY,PHONE,
--      EVENTSD,FAX,ADDRESS,EADDRESS,SINCEDATE,TEAM,SPOT,BUSINESS,CONTENTS,
--      PAYMENTS,BILL,EMAIL,RPHONE,RPHONE2,PAYMETHOD,PAYREGDATE,PUBLICATION,
--      REGDATE,DCDHL,DCTNT,DCUPS,DCEMS,IDC_DHL,IDC_TNT,C_FUEL,C_FUEL2,C_FUEL3,
--      SALEDATE,file1,filenum,DEL
--
--    전표용:
--      IDX,COMPANY,EADDRESS,PHONE,FAX,BUYERCODE,CONTACTNAME,BEADDRESS,BPHONE,
--      BFAX,ARRIVAL_N,FACTORY_A,TRANSIT_A,WEIGHT,AMOUNT,FUEL,DANGA_NUM,
--      DANGA_NAME,PRICE,DCYUL,COLMONEY,TSNUM,TSWEIGHT,TSAMOUNT,DESCRIPTION,
--      PCS,UNIT,MAMOUNT,DATESHIP,CONTENTS,SPOT,COMM,REGDATE,BLNUM,INOUT,
--      COLLECT,SALEDATE,DIVISION,BUSINESS,TEAM,LICENCE,BILL,TAXES,DEPOSIT,
--      file1,filenum,remark,GUBUN,TSWEIGHT2,UPTDATE,UPTUSER
--
--    파일이 커서 import 가 끊기면 phpMyAdmin 대신 SSH 에서:
--      mysql -u USER -p DB < 파일.sql
--    또는 CSV 를 나눠서 여러 번 올리세요. staging 은 여러 번 넣어도
--    staging_id 가 계속 늘어나므로 문제없습니다.
-- ============================================================================


-- ============================================================================
--  [D] import 끝난 뒤 실행 — raw_json · 지문 채우고 NOT NULL 복구
-- ============================================================================

-- 거래처
UPDATE companies_staging SET raw_json = JSON_OBJECT(
  'IDX', IDX, 'CUTCODE', CUTCODE, 'COMPANY', COMPANY, 'ECOMPANY', ECOMPANY,
  'PRESIDENT', PRESIDENT, 'BNUMBER', BNUMBER, 'JUMBER', JUMBER,
  'CATEGORY', CATEGORY, 'PHONE', PHONE, 'EVENTSD', EVENTSD, 'FAX', FAX,
  'ADDRESS', ADDRESS, 'EADDRESS', EADDRESS, 'SINCEDATE', SINCEDATE,
  'TEAM', TEAM, 'SPOT', SPOT, 'BUSINESS', BUSINESS, 'CONTENTS', CONTENTS,
  'PAYMENTS', PAYMENTS, 'BILL', BILL, 'EMAIL', EMAIL, 'RPHONE', RPHONE,
  'RPHONE2', RPHONE2, 'PAYMETHOD', PAYMETHOD, 'PAYREGDATE', PAYREGDATE,
  'PUBLICATION', PUBLICATION, 'REGDATE', REGDATE, 'DCDHL', DCDHL,
  'DCTNT', DCTNT, 'DCUPS', DCUPS, 'DCEMS', DCEMS, 'IDC_DHL', IDC_DHL,
  'IDC_TNT', IDC_TNT, 'C_FUEL', C_FUEL, 'C_FUEL2', C_FUEL2, 'C_FUEL3', C_FUEL3,
  'SALEDATE', SALEDATE, 'file1', file1, 'filenum', filenum, 'DEL', `DEL`
) WHERE raw_json IS NULL;

UPDATE companies_staging
   SET raw_sha256 = SHA2(CONCAT_WS('|',
         COALESCE(IDX,''), COALESCE(CUTCODE,''), COALESCE(COMPANY,''),
         COALESCE(BNUMBER,''), COALESCE(PHONE,''), COALESCE(`DEL`,'')), 256)
 WHERE raw_sha256 IS NULL;

-- 전표
UPDATE shipments_staging SET raw_json = JSON_OBJECT(
  'IDX', IDX, 'COMPANY', COMPANY, 'EADDRESS', EADDRESS, 'PHONE', PHONE,
  'FAX', FAX, 'BUYERCODE', BUYERCODE, 'CONTACTNAME', CONTACTNAME,
  'BEADDRESS', BEADDRESS, 'BPHONE', BPHONE, 'BFAX', BFAX,
  'ARRIVAL_N', ARRIVAL_N, 'FACTORY_A', FACTORY_A, 'TRANSIT_A', TRANSIT_A,
  'WEIGHT', WEIGHT, 'AMOUNT', AMOUNT, 'FUEL', FUEL, 'DANGA_NUM', DANGA_NUM,
  'DANGA_NAME', DANGA_NAME, 'PRICE', PRICE, 'DCYUL', DCYUL,
  'COLMONEY', COLMONEY, 'TSNUM', TSNUM, 'TSWEIGHT', TSWEIGHT,
  'TSAMOUNT', TSAMOUNT, 'DESCRIPTION', DESCRIPTION, 'PCS', PCS, 'UNIT', UNIT,
  'MAMOUNT', MAMOUNT, 'DATESHIP', DATESHIP, 'CONTENTS', CONTENTS,
  'SPOT', SPOT, 'COMM', COMM, 'REGDATE', REGDATE, 'BLNUM', BLNUM,
  'INOUT', `INOUT`, 'COLLECT', COLLECT, 'SALEDATE', SALEDATE,
  'DIVISION', DIVISION, 'BUSINESS', BUSINESS, 'TEAM', TEAM,
  'LICENCE', LICENCE, 'BILL', BILL, 'TAXES', TAXES, 'DEPOSIT', DEPOSIT,
  'file1', file1, 'filenum', filenum, 'remark', remark, 'GUBUN', GUBUN,
  'TSWEIGHT2', TSWEIGHT2, 'UPTDATE', UPTDATE, 'UPTUSER', UPTUSER
) WHERE raw_json IS NULL;

UPDATE shipments_staging
   SET raw_sha256 = SHA2(CONCAT_WS('|',
         COALESCE(IDX,''), COALESCE(BLNUM,''), COALESCE(COMPANY,''),
         COALESCE(AMOUNT,''), COALESCE(SALEDATE,'')), 256)
 WHERE raw_sha256 IS NULL;

-- 안전장치 복구
ALTER TABLE companies_staging MODIFY raw_json JSON NOT NULL;
ALTER TABLE shipments_staging MODIFY raw_json JSON NOT NULL;


-- ============================================================================
--  [E] import 검증 — 13·14번 실행 전에 반드시
-- ============================================================================

-- 건수가 MSSQL 에서 센 값과 같아야 합니다
SELECT (SELECT COUNT(*) FROM companies_staging) AS 거래처_staging,
       (SELECT COUNT(*) FROM shipments_staging) AS 전표_staging;

-- IDX 가 비었거나 중복인 행 — 둘 다 0 이어야 합니다. IDX 는 원본에서 NOT NULL 입니다
SELECT (SELECT COUNT(*) FROM companies_staging WHERE COALESCE(TRIM(IDX),'')='')  AS 거래처_IDX없음,
       (SELECT COUNT(*) FROM shipments_staging WHERE COALESCE(TRIM(IDX),'')='')  AS 전표_IDX없음;

SELECT IDX, COUNT(*) c FROM companies_staging GROUP BY IDX HAVING c>1;
SELECT IDX, COUNT(*) c FROM shipments_staging GROUP BY IDX HAVING c>1;

-- 한글이 깨지지 않았는지 눈으로 확인
SELECT staging_id, IDX, CUTCODE, COMPANY, PRESIDENT FROM companies_staging LIMIT 10;
SELECT staging_id, IDX, BLNUM, COMPANY, AMOUNT, SALEDATE FROM shipments_staging LIMIT 10;

-- ★ 이관 전에 답을 알아야 하는 것들
SELECT `DEL`, COUNT(*) AS 건수 FROM companies_staging GROUP BY `DEL` ORDER BY 건수 DESC;
SELECT LICENCE, TAXES, COUNT(*) AS 건수 FROM shipments_staging
 GROUP BY LICENCE, TAXES ORDER BY 건수 DESC;
SELECT `INOUT`, COUNT(*) AS 건수 FROM shipments_staging GROUP BY `INOUT` ORDER BY 건수 DESC;

-- 원본 금액 합계 — 이관 후 이 값과 대조합니다
SELECT SUM(CAST(REPLACE(REPLACE(AMOUNT,',',''),' ','') AS DECIMAL(18,2))) AS 원본_AMOUNT합
FROM shipments_staging
WHERE REPLACE(REPLACE(COALESCE(AMOUNT,''),',',''),' ','') REGEXP '^-?[0-9]+(\\.[0-9]+)?$';

-- 숫자·날짜로 못 바꾸는 값 건수
SELECT
  SUM(REPLACE(REPLACE(COALESCE(AMOUNT,''),',',''),' ','') NOT REGEXP '^-?[0-9]+(\\.[0-9]+)?$'
      AND COALESCE(TRIM(AMOUNT),'') <> '')                          AS 금액변환실패,
  SUM(REPLACE(REPLACE(COALESCE(WEIGHT,''),',',''),' ','') NOT REGEXP '^[0-9]+(\\.[0-9]+)?$'
      AND COALESCE(TRIM(WEIGHT),'') <> '')                          AS 중량변환실패,
  SUM(SALEDATE NOT REGEXP '^[0-9]{4}[-/.][0-9]{1,2}[-/.][0-9]{1,2}'
      AND SALEDATE NOT REGEXP '^[0-9]{8}$'
      AND COALESCE(TRIM(SALEDATE),'') <> '')                        AS 전표일변환실패
FROM shipments_staging;

-- 여기까지 확인했으면 staging 덤프를 먼저 받아두고 13·14번을 실행하세요
--   mysqldump -u USER -p DB companies_staging shipments_staging > 원본staging.sql
