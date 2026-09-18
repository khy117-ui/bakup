-- ============================================================================
--  거래처 — [A] 보존장치 만들기
--  실행 순서 : 10 → 11 → 12 → **13a → 14a** → (CSV 적재) → 13b → 14b
--
--  ★ 관리자 계정으로 실행하세요.
--    CREATE TABLE · CREATE TRIGGER · ALTER TABLE 을 씁니다.
--    앱 계정(gp_app)에는 이 권한이 없습니다 — 그게 정상입니다.
--
--  한 번만 실행합니다. 두 번 실행하면 "이미 있다" 는 에러가 납니다.
--
--  트리거는 만든 계정(DEFINER)의 권한으로 동작합니다. 따라서 앱 계정에
--  TRIGGER 권한이 없어도, 앱이 거래처를 수정하면 이력은 정상으로 남습니다.
-- ============================================================================

SET NAMES utf8mb4;

-- ============================================================================
--  전제조건 — 12_seed.sql 이 먼저 실행됐는지 확인합니다
--  아래 값이 하나라도 0 이면 **여기서 멈추세요.** 할인율이 조용히 0건 들어갑니다
-- ============================================================================

SELECT (SELECT COUNT(*) FROM business_entities WHERE code = 'GPA')              AS GPA사업자_1이어야함,
       (SELECT COUNT(*) FROM carriers WHERE code IN ('DHL','UPS','EMS'))         AS 운송사_3이상이어야함,
       (SELECT COUNT(*) FROM companies)                                          AS 기존거래처_0이어야함;

-- ============================================================================
--  0. 공용 — 이관 오류 기록 (14b_shipments_migrate.sql 도 같이 씁니다)
-- ============================================================================

CREATE TABLE IF NOT EXISTS migration_errors (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_table VARCHAR(64)  NOT NULL,
  source_idx   VARCHAR(20)  NULL             COMMENT '원본 IDX',
  column_name  VARCHAR(64)  NOT NULL,
  raw_value    VARCHAR(500) NULL             COMMENT '변환에 실패한 원본값 그대로',
  reason       VARCHAR(255) NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_me_src (source_table, column_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='이관 중 변환 실패 기록 — 값을 버리지 않고 남긴다';


-- ============================================================================
--  1. 원본 보관 — companies_staging
--
--  CUSTOMERS 40컬럼을 있는 그대로 받습니다. 타입 변환도, 정제도, 판단도 없습니다.
--  전부 VARCHAR / TEXT 입니다. 여기서는 어떤 데이터도 버려지지 않습니다.
--
--  raw_json 은 이중 안전장치입니다. 컬럼 목록이 실제와 어긋나도 원본 값이 남습니다.
--  적재 스크립트는 CSV/백업의 헤더를 그대로 JSON 으로 만들어 함께 넣습니다.
-- ============================================================================

CREATE TABLE companies_staging (
  staging_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- CUSTOMERS 40컬럼 — 원본 순서 그대로, 타입 변환 없음
  IDX          VARCHAR(20)  NULL,
  CUTCODE      VARCHAR(30)  NULL,
  COMPANY      VARCHAR(50)  NULL,
  ECOMPANY     VARCHAR(50)  NULL,
  PRESIDENT    VARCHAR(30)  NULL,
  BNUMBER      VARCHAR(30)  NULL,
  JUMBER       VARCHAR(30)  NULL,
  CATEGORY     VARCHAR(20)  NULL,
  PHONE        VARCHAR(20)  NULL,
  EVENTSD      VARCHAR(20)  NULL,
  FAX          VARCHAR(20)  NULL,
  ADDRESS      VARCHAR(500) NULL,
  EADDRESS     VARCHAR(500) NULL,
  SINCEDATE    VARCHAR(20)  NULL,
  TEAM         VARCHAR(20)  NULL,
  SPOT         VARCHAR(20)  NULL,
  BUSINESS     VARCHAR(20)  NULL,
  CONTENTS     VARCHAR(500) NULL,
  PAYMENTS     VARCHAR(20)  NULL,
  BILL         VARCHAR(20)  NULL,
  EMAIL        VARCHAR(100) NULL,
  RPHONE       VARCHAR(20)  NULL              COMMENT '담당자 전화 1 — 이름 컬럼은 원본에 없음',
  RPHONE2      VARCHAR(20)  NULL              COMMENT '담당자 전화 2',
  PAYMETHOD    VARCHAR(20)  NULL,
  PAYREGDATE   VARCHAR(30)  NULL,
  PUBLICATION  VARCHAR(20)  NULL,
  REGDATE      VARCHAR(30)  NULL              COMMENT 'datetime 원본 — 문자열로 받아 뒤에서 변환',
  DCDHL        VARCHAR(20)  NULL,
  DCTNT        VARCHAR(20)  NULL,
  DCUPS        VARCHAR(20)  NULL,
  DCEMS        VARCHAR(20)  NULL,
  IDC_DHL      VARCHAR(20)  NULL,
  IDC_TNT      VARCHAR(20)  NULL,
  C_FUEL       VARCHAR(20)  NULL,
  C_FUEL2      VARCHAR(20)  NULL,
  C_FUEL3      VARCHAR(20)  NULL,
  SALEDATE     VARCHAR(20)  NULL,
  file1        VARCHAR(500) NULL              COMMENT '첨부파일 경로/이름',
  filenum      VARCHAR(50)  NULL,
  `DEL`        VARCHAR(10)  NULL,

  -- 안전장치
  raw_json     JSON NOT NULL                  COMMENT '원본 행 전체. 컬럼 목록이 틀려도 값이 남는다',
  raw_sha256   CHAR(64) NULL                  COMMENT '원본 행 지문 — 이관 후 대조용',
  loaded_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  source_file  VARCHAR(255) NULL              COMMENT '어느 백업/CSV 에서 왔는지',

  PRIMARY KEY (staging_id),
  KEY ix_stg_idx (IDX),
  KEY ix_stg_cutcode (CUTCODE),
  KEY ix_stg_company (COMPANY)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='CUSTOMERS 원본 무손실 보관 — 적재 후 읽기 전용. 절대 UPDATE/DELETE 하지 않음';


-- ============================================================================
--  2. 미해석 · 미배치 컬럼 보관 — 버리지 않고 옮겨둡니다
--
--  뜻이 확인되지 않은 컬럼과, 신규 스키마에 자리가 없는 컬럼을 모두 담습니다.
--  모른다고 버리면 복구할 수 없습니다. 의미가 확정되면 정식 컬럼으로 옮깁니다.
-- ============================================================================

CREATE TABLE companies_legacy_extra (
  company_id      BIGINT UNSIGNED NOT NULL,
  eventsd         VARCHAR(20) NULL          COMMENT '의미 불명',
  bill            VARCHAR(20) NULL          COMMENT '청구 방식으로 추정',
  publication     VARCHAR(20) NULL          COMMENT '계산서 발행 방식으로 추정',
  saledate        VARCHAR(20) NULL          COMMENT 'IS_SALES 에도 같은 이름이 있어 혼동',
  c_fuel          VARCHAR(20) NULL          COMMENT '업체별 유류할증률 1',
  c_fuel2         VARCHAR(20) NULL          COMMENT '업체별 유류할증률 2',
  c_fuel3         VARCHAR(20) NULL          COMMENT '업체별 유류할증률 3',
  legacy_del      VARCHAR(10) NULL          COMMENT 'CUSTOMERS.DEL 원본값 — 해석 확정 전까지 여기 보관',
  legacy_category VARCHAR(20) NULL          COMMENT 'CATEGORY 원본 (업태로 추정해 옮겼으나 원본도 남김)',
  legacy_business VARCHAR(20) NULL          COMMENT 'BUSINESS 원본 (종목/사업부 여부 미확정)',
  PRIMARY KEY (company_id),
  KEY ix_cle_del (legacy_del),
  CONSTRAINT fk_cle_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='CUSTOMERS 미해석 컬럼 보관 — 의미 확정 후 정식 컬럼으로 이동';


-- ============================================================================
--  3. 변경 이력 — companies_history
--
--  거래처가 바뀌거나 지워질 때 **바뀌기 전 행 전체**를 남깁니다.
--  실수로 덮어써도, 잘못된 일괄 UPDATE 를 돌려도 여기서 되돌릴 수 있습니다.
-- ============================================================================

CREATE TABLE companies_history (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id  BIGINT UNSIGNED NOT NULL,
  action      VARCHAR(10) NOT NULL          COMMENT 'UPDATE / DELETE',
  row_before  JSON NOT NULL                 COMMENT '변경 직전 행 전체',
  changed_by  VARCHAR(100) NULL             COMMENT 'DB 세션 사용자',
  app_user    VARCHAR(100) NULL             COMMENT '앱이 @app_user 세션변수로 넣은 담당자',
  changed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_ch_company (company_id, changed_at),
  KEY ix_ch_action (action, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='거래처 변경 이력 — 복구용';

DELIMITER $$

CREATE TRIGGER trg_companies_before_update
BEFORE UPDATE ON companies FOR EACH ROW
BEGIN
  INSERT INTO companies_history (company_id, action, row_before, changed_by, app_user)
  VALUES (OLD.id, 'UPDATE', JSON_OBJECT(
      'id', OLD.id, 'company_code', OLD.company_code,
      'name_ko', OLD.name_ko, 'name_en', OLD.name_en,
      'representative', OLD.representative,
      'business_number', OLD.business_number, 'corp_number', OLD.corp_number,
      'business_type', OLD.business_type, 'business_item', OLD.business_item,
      'phone', OLD.phone, 'fax', OLD.fax, 'email', OLD.email, 'homepage', OLD.homepage,
      'zipcode', OLD.zipcode, 'address_ko', OLD.address_ko, 'address_en', OLD.address_en,
      'joined_on', OLD.joined_on,
      'sales_team', OLD.sales_team, 'sales_rep', OLD.sales_rep,
      'trade_status', OLD.trade_status, 'trade_grade', OLD.trade_grade,
      'payment_method', OLD.payment_method, 'payment_terms', OLD.payment_terms,
      'billing_day', OLD.billing_day, 'tax_email', OLD.tax_email,
      'memo', OLD.memo,
      'created_at', OLD.created_at, 'updated_at', OLD.updated_at,
      'deleted_at', OLD.deleted_at, 'legacy_idx', OLD.legacy_idx
  ), CURRENT_USER(), @app_user);
END$$

CREATE TRIGGER trg_companies_before_delete
BEFORE DELETE ON companies FOR EACH ROW
BEGIN
  INSERT INTO companies_history (company_id, action, row_before, changed_by, app_user)
  VALUES (OLD.id, 'DELETE', JSON_OBJECT(
      'id', OLD.id, 'company_code', OLD.company_code,
      'name_ko', OLD.name_ko, 'name_en', OLD.name_en,
      'representative', OLD.representative,
      'business_number', OLD.business_number, 'corp_number', OLD.corp_number,
      'business_type', OLD.business_type, 'business_item', OLD.business_item,
      'phone', OLD.phone, 'fax', OLD.fax, 'email', OLD.email, 'homepage', OLD.homepage,
      'zipcode', OLD.zipcode, 'address_ko', OLD.address_ko, 'address_en', OLD.address_en,
      'joined_on', OLD.joined_on,
      'sales_team', OLD.sales_team, 'sales_rep', OLD.sales_rep,
      'trade_status', OLD.trade_status, 'trade_grade', OLD.trade_grade,
      'payment_method', OLD.payment_method, 'payment_terms', OLD.payment_terms,
      'billing_day', OLD.billing_day, 'tax_email', OLD.tax_email,
      'memo', OLD.memo,
      'created_at', OLD.created_at, 'updated_at', OLD.updated_at,
      'deleted_at', OLD.deleted_at, 'legacy_idx', OLD.legacy_idx
  ), CURRENT_USER(), @app_user);
END$$

DELIMITER ;


