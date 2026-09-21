-- ==========================================================================
--  GOODPOST 통합 업무관리 시스템 — 빈 스키마 설치 합본
--  대상 : MariaDB 10.4 이상 (collation 을 utf8mb4_unicode_ci 로 교체)
--
--  이 파일 하나로 10 → 11 → 12 가 순서대로 실행됩니다.
--  데이터 이관(13 · 14)은 들어 있지 않습니다. 스키마와 기준데이터만 올립니다.
--
--  실행 전에 00_check_server.sql 로 서버 종류를 먼저 확인하세요.
--  실행 후에 90_verify_schema.sql 로 합격 여부를 판정하세요.
--
--  되돌리기 : 이 단계에서는 업무 데이터가 없으므로
--             DROP DATABASE 후 다시 만드는 것이 가장 깔끔합니다.
-- ==========================================================================

-- ==========================================================================
--  << 10_schema_core.sql >>
-- ==========================================================================

-- ============================================================================
--  GOODPOST 통합 업무관리 시스템 — 신규 스키마 (핵심)
--  MySQL 8.0 / InnoDB / utf8mb4
--
--  범위 : 공통마스터 · 거래처 · 운송사단가 · 물류매출 · 매입손익 · 매출통계
--  (청구 · 세금계산서 · 수금 · 문서 · 게시판은 11_schema_billing.sql)
--
--  설계 원칙
--   1. 모든 거래 테이블에 business_entity_id — 두 사업자 회계 분리 (스펙 [47])
--   2. 금액은 DECIMAL. 기존 varchar 금액을 전부 정제해 넣는다
--   3. 세금구분은 전표가 아니라 항목마다 저장 (스펙 [24][30][31])
--   4. 단가 계산근거를 전표에 Snapshot 으로 고정 (스펙 [25])
--   5. 소프트삭제는 deleted_at NULL 여부로. char(1) 'Y'/'N' 안 씀
--   6. 외래키를 실제로 건다 (기존 DB 는 FK 0개였음)
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
--  1. 공통 · 마스터
-- ============================================================================

-- 사업자 (스펙 [4]) — (주)굿배송항공 / 굿포스트미디어
CREATE TABLE business_entities (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code              VARCHAR(10)  NOT NULL            COMMENT 'GPA / GPM',
  name_ko           VARCHAR(100) NOT NULL            COMMENT '(주) 굿배송항공',
  name_en           VARCHAR(150) NULL                COMMENT 'GOODPOST AIR CO., LTD.',
  business_number   VARCHAR(20)  NOT NULL            COMMENT '사업자등록번호 799-87-00630',
  corp_number       VARCHAR(20)  NULL                COMMENT '법인등록번호',
  representative    VARCHAR(50)  NOT NULL            COMMENT '대표자 — 김화연',
  doc_manager       VARCHAR(50)  NULL                COMMENT '문서 담당자 — 이창호 (인보이스 PERSON 란)',
  business_type     VARCHAR(50)  NULL                COMMENT '업태 — 서비스',
  business_item     VARCHAR(100) NULL                COMMENT '종목 — 국제물류주선업',
  zipcode           VARCHAR(10)  NULL,
  address_ko        VARCHAR(255) NULL                COMMENT '서울특별시 강서구 개화대로7길 4-17',
  address_en        VARCHAR(255) NULL,
  phone             VARCHAR(30)  NULL                COMMENT '02-6929-0666',
  fax               VARCHAR(30)  NULL                COMMENT '02-6929-0667',
  email             VARCHAR(100) NULL,
  logo_path         VARCHAR(255) NULL,
  stamp_path        VARCHAR(255) NULL                COMMENT '직인 이미지',
  tax_api_provider  VARCHAR(30)  NULL                COMMENT '팝빌 / 바로빌',
  tax_api_account   VARCHAR(100) NULL,
  tax_api_secret    VARBINARY(512) NULL              COMMENT '암호화 저장',
  doc_prefix_quote  VARCHAR(20)  NULL                COMMENT 'GPA-Q-',
  doc_prefix_stmt   VARCHAR(20)  NULL                COMMENT 'GPA-S-',
  doc_prefix_invoice VARCHAR(20) NULL                COMMENT 'GPA-INV-',
  is_active         TINYINT(1)   NOT NULL DEFAULT 1,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_be_code (code),
  UNIQUE KEY uq_be_bizno (business_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='사업자';

-- 사업자 입금계좌 — 인보이스에 국민/신한 두 개가 찍히므로 1:N
CREATE TABLE business_bank_accounts (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_entity_id BIGINT UNSIGNED NOT NULL,
  bank_name          VARCHAR(50)  NOT NULL           COMMENT '국민은행',
  account_no         VARCHAR(50)  NOT NULL           COMMENT '459601-01-584168',
  account_holder     VARCHAR(100) NOT NULL           COMMENT '(주)굿배송항공',
  sort_order         SMALLINT     NOT NULL DEFAULT 0,
  is_active          TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY ix_bba_entity (business_entity_id),
  CONSTRAINT fk_bba_entity FOREIGN KEY (business_entity_id) REFERENCES business_entities(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='사업자 입금계좌';

-- 관리자 (기존 IS_MEMBER)
CREATE TABLE admins (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  login_id      VARCHAR(50)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL                COMMENT 'password_hash() — 평문 저장 금지',
  name          VARCHAR(50)  NOT NULL,
  role_code     VARCHAR(30)  NOT NULL DEFAULT 'VIEWER'
                COMMENT 'SUPER_ADMIN/MANAGER/SALES/LOGISTICS/ACCOUNTING/BOARD_MANAGER/VIEWER',
  team          VARCHAR(50)  NULL,
  phone         VARCHAR(30)  NULL,
  email         VARCHAR(100) NULL,
  fail_count    SMALLINT     NOT NULL DEFAULT 0      COMMENT '5회 초과 시 잠금',
  locked_at     DATETIME     NULL,
  last_login_at DATETIME     NULL,
  last_login_ip VARCHAR(45)  NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at    DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_login (login_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='관리자';

-- 작업로그 (스펙 [45]) — 기존 IS_ADMIN_LOG + IS_SALES_Log 통합
CREATE TABLE activity_logs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id      BIGINT UNSIGNED NULL,
  admin_name    VARCHAR(50)  NOT NULL                COMMENT '계정이 지워져도 남기려고 이름을 복사',
  ip            VARCHAR(45)  NOT NULL,
  module        VARCHAR(50)  NOT NULL                COMMENT '매출전표 / 거래처 / 할인율 ...',
  ref_table     VARCHAR(64)  NULL,
  ref_id        BIGINT UNSIGNED NULL,
  ref_label     VARCHAR(100) NULL                    COMMENT 'AWB번호 등 사람이 읽는 식별자',
  action        VARCHAR(20)  NOT NULL                COMMENT 'CREATE/UPDATE/DELETE/ISSUE/PRINT/EXPORT',
  before_value  TEXT         NULL,
  after_value   TEXT         NULL,
  reason        VARCHAR(255) NULL                    COMMENT '금액·할인율 수동수정 시 필수',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_log_created (created_at),
  KEY ix_log_ref (ref_table, ref_id),
  KEY ix_log_admin (admin_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='작업로그';


-- ============================================================================
--  2. 거래처  ★ 1순위
--  기존 CUSTOMERS(40컬럼, PK 없음) 을 4개로 분해
-- ============================================================================

CREATE TABLE companies (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_code      VARCHAR(30)  NOT NULL            COMMENT '기존 CUTCODE',
  name_ko           VARCHAR(100) NOT NULL            COMMENT '기존 COMPANY',
  name_en           VARCHAR(150) NULL                COMMENT '기존 ECOMPANY',
  representative    VARCHAR(50)  NULL                COMMENT '기존 PRESIDENT',
  business_number   VARCHAR(20)  NULL                COMMENT '기존 BNUMBER 사업자등록번호',
  corp_number       VARCHAR(20)  NULL                COMMENT '기존 JUMBER 법인등록번호',
  business_type     VARCHAR(50)  NULL                COMMENT '기존 CATEGORY 업태 (확인필요)',
  business_item     VARCHAR(100) NULL                COMMENT '기존 BUSINESS 종목 (확인필요 — 통계축일 수도)',
  phone             VARCHAR(30)  NULL,
  fax               VARCHAR(30)  NULL,
  email             VARCHAR(100) NULL,
  homepage          VARCHAR(150) NULL,
  zipcode           VARCHAR(10)  NULL,
  address_ko        VARCHAR(255) NULL                COMMENT '기존 ADDRESS',
  address_en        VARCHAR(255) NULL                COMMENT '기존 EADDRESS',
  joined_on         DATE         NULL                COMMENT '기존 SINCEDATE (varchar→DATE 정제)',
  sales_team        VARCHAR(50)  NULL                COMMENT '기존 TEAM',
  sales_rep         VARCHAR(50)  NULL                COMMENT '기존 SPOT 영업담당자 (확인필요)',
  trade_status      VARCHAR(20)  NOT NULL DEFAULT 'ACTIVE'
                    COMMENT 'ACTIVE 거래중 / SUSPENDED 중지 / NEW 신규 / CLOSED 종료',
  trade_grade       CHAR(1)      NULL                COMMENT 'A / B / C',
  payment_method    VARCHAR(30)  NULL                COMMENT '기존 PAYMETHOD 계좌이체 등',
  payment_terms     VARCHAR(100) NULL                COMMENT '기존 PAYMENTS 월말마감 익월30일',
  billing_day       VARCHAR(30)  NULL                COMMENT '기존 PAYREGDATE 청구일',
  tax_email         VARCHAR(100) NULL                COMMENT '전자세금계산서 수신',
  memo              TEXT         NULL                COMMENT '기존 CONTENTS',
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at        DATETIME     NULL                COMMENT '기존 DEL char(1) 대체 — Y/N 의미 확인 후 변환',
  legacy_idx        INT          NULL                COMMENT '기존 CUSTOMERS.IDX — 이관 대조용',
  PRIMARY KEY (id),
  UNIQUE KEY uq_comp_code (company_code),
  KEY ix_comp_name (name_ko),
  KEY ix_comp_bizno (business_number),
  KEY ix_comp_status (trade_status, deleted_at),
  KEY ix_comp_legacy (legacy_idx)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='거래처';

-- 업체 담당자 (스펙 [11]) — 기존엔 CUSTOMERS.RPHONE/RPHONE2 로 2명까지만 가능했음
CREATE TABLE company_contacts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id   BIGINT UNSIGNED NOT NULL,
  name         VARCHAR(50)  NOT NULL,
  department   VARCHAR(50)  NULL,
  position     VARCHAR(50)  NULL,
  phone        VARCHAR(30)  NULL,
  mobile       VARCHAR(30)  NULL,
  email        VARCHAR(100) NULL,
  contact_type VARCHAR(20)  NOT NULL DEFAULT 'GENERAL'
               COMMENT 'GENERAL/LOGISTICS/SALES/ACCOUNTING/TAX/OTHER',
  is_primary   TINYINT(1)   NOT NULL DEFAULT 0       COMMENT '주담당 — 전표 작성 시 기본값',
  memo         VARCHAR(255) NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at   DATETIME     NULL,
  PRIMARY KEY (id),
  KEY ix_cc_company (company_id, is_primary),
  KEY ix_cc_type (contact_type),
  CONSTRAINT fk_cc_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='업체 담당자';

-- 거래처 첨부파일 (사업자등록증 · 계약서 · 통장사본)
CREATE TABLE company_files (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id    BIGINT UNSIGNED NOT NULL,
  file_type     VARCHAR(30)  NOT NULL                COMMENT 'BIZ_LICENSE 사업자등록증 / CONTRACT 계약서 / BANKBOOK 통장사본 / OTHER',
  original_name VARCHAR(255) NOT NULL,
  stored_path   VARCHAR(500) NOT NULL,
  mime_type     VARCHAR(100) NULL,
  size_bytes    BIGINT UNSIGNED NULL,
  uploaded_by   BIGINT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at    DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_cf_company (company_id, file_type),
  CONSTRAINT fk_cf_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='거래처 첨부파일';


-- ============================================================================
--  3. 운송사 · 단가 (스펙 [16][17][20])
-- ============================================================================

CREATE TABLE carriers (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code             VARCHAR(20)  NOT NULL             COMMENT 'DHL / FEDEX / UPS / EMS',
  name             VARCHAR(100) NOT NULL,
  tracking_enabled TINYINT(1)   NOT NULL DEFAULT 0,
  default_tax_type VARCHAR(10)  NOT NULL DEFAULT 'ZERO' COMMENT 'ZERO 영세 / TAXABLE 과세 / EXEMPT 면세',
  sort_order       SMALLINT     NOT NULL DEFAULT 0,
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_carrier_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='운송사';

CREATE TABLE carrier_services (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  carrier_id BIGINT UNSIGNED NOT NULL,
  code       VARCHAR(30)  NOT NULL                   COMMENT 'DHL-EW',
  name       VARCHAR(100) NOT NULL                   COMMENT 'EXPRESS WORLDWIDE',
  tax_type   VARCHAR(10)  NULL                       COMMENT 'NULL 이면 운송사 기본값',
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_svc (carrier_id, code),
  CONSTRAINT fk_svc_carrier FOREIGN KEY (carrier_id) REFERENCES carriers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='운송사 서비스';

CREATE TABLE carrier_zones (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  carrier_id   BIGINT UNSIGNED NOT NULL,
  zone_no      SMALLINT     NOT NULL                 COMMENT '1~9',
  country_code CHAR(2)      NOT NULL                 COMMENT 'US / JP / CN ...',
  country_name VARCHAR(100) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_zone (carrier_id, country_code),
  KEY ix_zone_no (carrier_id, zone_no),
  CONSTRAINT fk_zone_carrier FOREIGN KEY (carrier_id) REFERENCES carriers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='운송사 Zone-국가 매핑';

-- 가격표는 덮어쓰지 않고 버전으로 쌓는다 (스펙 [17])
CREATE TABLE carrier_rate_tables (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  carrier_id     BIGINT UNSIGNED NOT NULL,
  service_id     BIGINT UNSIGNED NULL,
  name           VARCHAR(100) NOT NULL               COMMENT 'DHL 2026 PRICE TABLE',
  effective_from DATE NOT NULL,
  effective_to   DATE NULL,
  status         VARCHAR(20) NOT NULL DEFAULT 'ACTIVE' COMMENT 'DRAFT/ACTIVE/EXPIRED',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by     BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY ix_rt_lookup (carrier_id, effective_from, effective_to),
  CONSTRAINT fk_rt_carrier FOREIGN KEY (carrier_id) REFERENCES carriers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='특송 기본가격표 (버전)';

-- 기존 TNT/UPS 의 money1~money11 컬럼을 행으로 분해
CREATE TABLE carrier_rates (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rate_table_id BIGINT UNSIGNED NOT NULL,
  zone_no       SMALLINT      NOT NULL,
  weight_from   DECIMAL(10,2) NOT NULL               COMMENT 'kg 초과',
  weight_to     DECIMAL(10,2) NOT NULL               COMMENT 'kg 이하',
  base_price    DECIMAL(15,2) NOT NULL,
  currency      CHAR(3)       NOT NULL DEFAULT 'KRW',
  PRIMARY KEY (id),
  UNIQUE KEY uq_rate (rate_table_id, zone_no, weight_from, weight_to),
  KEY ix_rate_lookup (rate_table_id, zone_no, weight_to),
  CONSTRAINT fk_rate_table FOREIGN KEY (rate_table_id) REFERENCES carrier_rate_tables(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Zone×중량 단가';

-- 유류할증료 (스펙 [20]) — 기존 IS_FUEL_CODE (cate/syear/smonth/money)
CREATE TABLE fuel_surcharges (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  carrier_id      BIGINT UNSIGNED NOT NULL,
  service_id      BIGINT UNSIGNED NULL,
  fuel_rate       DECIMAL(5,2) NOT NULL              COMMENT '% — 기존 money varchar 정제',
  calc_basis      VARCHAR(20)  NOT NULL DEFAULT 'AFTER_DISCOUNT'
                  COMMENT 'AFTER_DISCOUNT 할인후운임 / BASE_PRICE 기본가격 — 금액이 달라지므로 계약서 기준 확인',
  effective_from  DATE NOT NULL                      COMMENT '기존 syear+smonth 를 날짜로',
  effective_to    DATE NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_fuel_lookup (carrier_id, effective_from, effective_to),
  CONSTRAINT fk_fuel_carrier FOREIGN KEY (carrier_id) REFERENCES carriers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='유류할증료';


-- ============================================================================
-- 업체별 운송사 할인율 (스펙 [19])
-- 기존 CUSTOMERS.DCDHL/DCTNT/DCUPS/DCEMS/IDC_DHL/IDC_TNT 컬럼을 행으로 분해.
-- 운송사가 늘어도 컬럼을 추가할 필요가 없다.
CREATE TABLE company_carrier_terms (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id     BIGINT UNSIGNED NOT NULL,
  carrier_id     BIGINT UNSIGNED NOT NULL,
  service_id     BIGINT UNSIGNED NULL                COMMENT 'NULL = 해당 운송사 전 서비스',
  trade_type     VARCHAR(10)  NOT NULL DEFAULT 'ALL' COMMENT 'EXPORT 수출 / IMPORT 수입 / ALL — 기존 DC* 와 IDC_* 의 구분',
  discount_rate  DECIMAL(5,2) NOT NULL DEFAULT 0.00  COMMENT '% — 기존 varchar 값 정제',
  fuel_applied   TINYINT(1)   NOT NULL DEFAULT 1     COMMENT '유류할증 적용 여부',
  effective_from DATE         NOT NULL,
  effective_to   DATE         NULL                   COMMENT 'NULL = 무기한',
  memo           VARCHAR(255) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_cct_lookup (company_id, carrier_id, trade_type, effective_from),
  CONSTRAINT fk_cct_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_cct_carrier FOREIGN KEY (carrier_id) REFERENCES carriers(id),
  CONSTRAINT fk_cct_service FOREIGN KEY (service_id) REFERENCES carrier_services(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='업체별 운송사 할인율';


--  4. 물류 · 매출전표  ★ 가장 중요
--  기존 IS_SALES(51컬럼) + IS_LDX(HAWB) 를 4개로 분해
-- ============================================================================

CREATE TABLE shipments (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_entity_id BIGINT UNSIGNED NOT NULL        COMMENT '스펙 [47] — 사업자 분리',
  company_id         BIGINT UNSIGNED NOT NULL,
  contact_id         BIGINT UNSIGNED NULL,

  -- 운송장 번호 3종 (스펙 [27]) — 기존엔 IS_SALES.BLNUM 하나뿐이었음
  awb_no             VARCHAR(50)  NOT NULL           COMMENT '자사 HAWB — 기존 BLNUM',
  awb_source         VARCHAR(10)  NOT NULL DEFAULT 'HOUSE'
                     COMMENT 'HOUSE 자사채번 / CARRIER 운송사번호 그대로 (수출 건에 섞여 있음)',
  mawb_no            VARCHAR(50)  NULL               COMMENT '항공사 마스터 180-45892310',
  barcode_path       VARCHAR(255) NULL               COMMENT 'CODE128 PNG 경로 (스펙 [26])',

  voucher_date       DATE NOT NULL                   COMMENT '전표일 — 기존 SALEDATE varchar 정제',
  ship_date          DATE NULL                       COMMENT '발송일 — 기존 DATESHIP',
  trade_type         VARCHAR(10) NOT NULL            COMMENT 'EXPORT / IMPORT — 기존 INOUT',

  carrier_id         BIGINT UNSIGNED NOT NULL,
  service_id         BIGINT UNSIGNED NULL,

  origin_country     CHAR(2)      NULL,
  origin_city        VARCHAR(50)  NULL,
  dest_country       CHAR(2)      NULL,
  dest_city          VARCHAR(50)  NULL               COMMENT '기존 ARRIVAL_N',
  zone_no            SMALLINT     NULL,

  actual_weight      DECIMAL(10,2) NULL              COMMENT '실중량 — 기존 WEIGHT varchar 정제',
  volume_weight      DECIMAL(10,2) NULL              COMMENT '부피중량 — 가로×세로×높이÷5000',
  charge_weight      DECIMAL(10,2) NULL              COMMENT '청구중량 = 둘 중 큰 값',
  package_count      SMALLINT     NULL               COMMENT '기존 PCS',

  -- 단가 Snapshot (스펙 [25]) — 계산 당시 값으로 고정. 단가표가 바뀌어도 전표는 불변
  snap_base_price     DECIMAL(15,2) NULL             COMMENT '기본가격',
  snap_discount_rate  DECIMAL(5,2)  NULL             COMMENT '할인율 % — 기존 DCYUL',
  snap_discount_amt   DECIMAL(15,2) NULL,
  snap_discounted     DECIMAL(15,2) NULL             COMMENT '할인 후 운임',
  snap_fuel_rate      DECIMAL(5,2)  NULL             COMMENT '유류할증률 % — 기존 FUEL',
  snap_fuel_amt       DECIMAL(15,2) NULL,
  snap_supply_price   DECIMAL(15,2) NULL             COMMENT '공급가격 = 할인후 + 유류할증',
  snap_rate_table_id  BIGINT UNSIGNED NULL           COMMENT '어느 가격표 버전을 썼는지',
  snap_taken_at       DATETIME NULL,

  status             VARCHAR(20) NOT NULL DEFAULT 'DRAFT'
                     COMMENT 'DRAFT/CONFIRMED/BILLED/PAID/CANCELLED',
  approval_status    VARCHAR(20) NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING/APPROVED',
  sales_team         VARCHAR(50) NULL                COMMENT '기존 TEAM',
  sales_rep          VARCHAR(50) NULL                COMMENT '기존 COMM / SPOT',
  remark             TEXT NULL                       COMMENT '기존 CONTENTS / remark',

  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by         BIGINT UNSIGNED NULL,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by         BIGINT UNSIGNED NULL,
  deleted_at         DATETIME NULL,
  legacy_idx         INT NULL                        COMMENT '기존 IS_SALES.IDX',

  PRIMARY KEY (id),
  UNIQUE KEY uq_ship_awb (business_entity_id, awb_no),
  KEY ix_ship_company (company_id, voucher_date),
  KEY ix_ship_date (business_entity_id, voucher_date),
  KEY ix_ship_carrier (carrier_id, voucher_date),
  KEY ix_ship_status (status, voucher_date),
  KEY ix_ship_legacy (legacy_idx),
  CONSTRAINT fk_ship_entity  FOREIGN KEY (business_entity_id) REFERENCES business_entities(id),
  CONSTRAINT fk_ship_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_ship_carrier FOREIGN KEY (carrier_id) REFERENCES carriers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='매출전표 · AWB';

-- SHIPPER / CONSIGNEE (스펙 [23]) — 기존엔 IS_SALES 에 COMPANY/EADDRESS/PHONE/FAX 와
-- BUYERCODE/BEADDRESS/BPHONE/BFAX 로 두 벌이 박혀 있었음
CREATE TABLE shipment_parties (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shipment_id BIGINT UNSIGNED NOT NULL,
  party_type  VARCHAR(12)  NOT NULL                  COMMENT 'SHIPPER / CONSIGNEE',
  company_name VARCHAR(150) NULL,
  contact_name VARCHAR(100) NULL,
  address     VARCHAR(500) NULL,
  city        VARCHAR(50)  NULL,
  country     CHAR(2)      NULL,
  zipcode     VARCHAR(20)  NULL,
  phone       VARCHAR(50)  NULL,
  fax         VARCHAR(50)  NULL,
  email       VARCHAR(100) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_party (shipment_id, party_type),
  CONSTRAINT fk_party_ship FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='SHIPPER / CONSIGNEE';

-- MANIFEST (스펙 [23]) — 기존엔 DESCRIPTION/PCS/UNIT 1건만 가능했음
CREATE TABLE shipment_items (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shipment_id BIGINT UNSIGNED NOT NULL,
  line_no     SMALLINT NOT NULL,
  item_name   VARCHAR(200) NOT NULL,
  hs_code     VARCHAR(20)  NULL,
  origin      CHAR(2)      NULL,
  qty         DECIMAL(12,2) NULL,
  unit        VARCHAR(20)  NULL,
  unit_price  DECIMAL(15,2) NULL,
  amount      DECIMAL(15,2) NULL,
  currency    CHAR(3) NOT NULL DEFAULT 'USD',
  remark      VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sitem (shipment_id, line_no),
  CONSTRAINT fk_sitem_ship FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='MANIFEST 품목';

-- 매출 비용항목 (스펙 [24]) ★ 세금구분을 전표가 아니라 항목마다 저장
-- 기존엔 IS_SALES.AMOUNT 하나에 뭉쳐 있고 LICENCE/TAXES 로 영세/과세를 전표 단위로만 구분
CREATE TABLE shipment_charges (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shipment_id   BIGINT UNSIGNED NOT NULL,
  line_no       SMALLINT NOT NULL,
  charge_type   VARCHAR(30) NOT NULL
                COMMENT 'AIR_FREIGHT 특송운임 / DOMESTIC 국내운송 / HANDLING / CUSTOMS 통관 / STORAGE 창고 / OTHER',
  item_name     VARCHAR(200)  NOT NULL,
  qty           DECIMAL(12,2) NOT NULL DEFAULT 1,
  unit_price    DECIMAL(15,2) NOT NULL DEFAULT 0,
  supply_amount DECIMAL(15,2) NOT NULL DEFAULT 0     COMMENT '공급가액',
  tax_type      VARCHAR(10)   NOT NULL DEFAULT 'ZERO' COMMENT 'ZERO 영세 / TAXABLE 과세 / EXEMPT 면세',
  tax_rate      DECIMAL(5,2)  NOT NULL DEFAULT 0.00  COMMENT '0.00 또는 10.00',
  tax_amount    DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_amount  DECIMAL(15,2) NOT NULL DEFAULT 0     COMMENT '공급가액 + 세액',
  remark        VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_charge (shipment_id, line_no),
  KEY ix_charge_tax (tax_type),
  CONSTRAINT fk_charge_ship FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='매출 비용항목 · 항목별 세금구분';

-- 운송사 추적번호 (스펙 [27]) — 한 건에 여러 개 가능
CREATE TABLE tracking_numbers (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shipment_id     BIGINT UNSIGNED NOT NULL,
  carrier_id      BIGINT UNSIGNED NOT NULL,
  tracking_no     VARCHAR(60) NOT NULL,
  current_status  VARCHAR(50) NULL,
  current_location VARCHAR(100) NULL,
  accepted_on     DATE NULL,
  shipped_on      DATE NULL,
  eta_on          DATE NULL,
  delivered_at    DATETIME NULL,
  last_checked_at DATETIME NULL                      COMMENT '스펙 [29] 수동 조회 시각',
  last_checked_by BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trk (carrier_id, tracking_no),
  KEY ix_trk_ship (shipment_id),
  CONSTRAINT fk_trk_ship FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
  CONSTRAINT fk_trk_carrier FOREIGN KEY (carrier_id) REFERENCES carriers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='운송사 추적번호';

-- 배송 이벤트 — 기존엔 저장하지 않았음. 신규에서 새로 쌓는다
CREATE TABLE tracking_events (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tracking_number_id BIGINT UNSIGNED NOT NULL,
  event_at           DATETIME NOT NULL,
  location           VARCHAR(100) NULL,
  status             VARCHAR(100) NOT NULL,
  description        VARCHAR(255) NULL,
  fetched_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_evt (tracking_number_id, event_at, status),
  CONSTRAINT fk_evt_trk FOREIGN KEY (tracking_number_id) REFERENCES tracking_numbers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='배송 이력';


-- ============================================================================
--  5. 매입 · 손익 (스펙 [33])
-- ============================================================================

CREATE TABLE purchases (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_entity_id BIGINT UNSIGNED NOT NULL,
  shipment_id        BIGINT UNSIGNED NULL            COMMENT 'NULL = 특정 AWB 에 안 붙는 매입 (정산서 등)',
  vendor_name        VARCHAR(100) NOT NULL           COMMENT 'DHL / 한성통운 / 관세법인',
  carrier_id         BIGINT UNSIGNED NULL,
  purchase_date      DATE NOT NULL,
  charge_type        VARCHAR(30) NOT NULL            COMMENT 'AIR_FREIGHT / DOMESTIC / CUSTOMS / STORAGE / OTHER',
  supply_amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_type           VARCHAR(10)   NOT NULL DEFAULT 'ZERO',
  tax_amount         DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_amount       DECIMAL(15,2) NOT NULL DEFAULT 0,
  recon_status       VARCHAR(20) NOT NULL DEFAULT 'UNMATCHED'
                     COMMENT 'MATCHED 일치 / DIFF 차액 / UNMATCHED 미대사',
  recon_diff         DECIMAL(15,2) NULL              COMMENT '전표 예상액과의 차이',
  is_paid            TINYINT(1) NOT NULL DEFAULT 0,
  remark             VARCHAR(255) NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at         DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_pur_ship (shipment_id),
  KEY ix_pur_date (business_entity_id, purchase_date),
  KEY ix_pur_recon (recon_status),
  CONSTRAINT fk_pur_entity FOREIGN KEY (business_entity_id) REFERENCES business_entities(id),
  CONSTRAINT fk_pur_ship   FOREIGN KEY (shipment_id) REFERENCES shipments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='매입';


-- ============================================================================
--  6. 매출통계 뷰  ★
--  기존 sp_STATISTICS_sum / sp_CUSTOMERS_sum / sp_SALES_TEAM_sum /
--       sp_Performance_sum / sp_BUSINESS_sum 을 대체.
--  기존은 sum(cast(REPLACE(AMOUNT,',','') as float)) 로 매번 정제했지만
--  신규는 DECIMAL 이라 그냥 SUM 한다.
-- ============================================================================

-- 전표 단위 집계 (영세/과세/VAT 분리)
CREATE OR REPLACE VIEW v_shipment_totals AS
SELECT
    s.id                AS shipment_id,
    s.business_entity_id,
    s.company_id,
    s.carrier_id,
    s.voucher_date,
    s.trade_type,
    s.charge_weight,
    COALESCE(SUM(CASE WHEN c.tax_type = 'ZERO'    THEN c.supply_amount END), 0) AS zero_supply,
    COALESCE(SUM(CASE WHEN c.tax_type = 'TAXABLE' THEN c.supply_amount END), 0) AS taxable_supply,
    COALESCE(SUM(CASE WHEN c.tax_type = 'EXEMPT'  THEN c.supply_amount END), 0) AS exempt_supply,
    COALESCE(SUM(c.supply_amount), 0) AS supply_total,
    COALESCE(SUM(c.tax_amount),    0) AS tax_total,
    COALESCE(SUM(c.total_amount),  0) AS grand_total
FROM shipments s
LEFT JOIN shipment_charges c ON c.shipment_id = s.id
WHERE s.deleted_at IS NULL
GROUP BY s.id;

-- 손익 (매출 − 매입)
CREATE OR REPLACE VIEW v_shipment_profit AS
SELECT
    t.shipment_id,
    t.business_entity_id,
    t.company_id,
    t.carrier_id,
    t.voucher_date,
    t.supply_total                                   AS revenue,
    COALESCE(p.purchase_total, 0)                    AS cost,
    t.supply_total - COALESCE(p.purchase_total, 0)   AS profit,
    CASE WHEN t.supply_total > 0
         THEN ROUND((t.supply_total - COALESCE(p.purchase_total, 0)) / t.supply_total * 100, 1)
         ELSE NULL END                               AS profit_rate
FROM v_shipment_totals t
LEFT JOIN (
    SELECT shipment_id, SUM(supply_amount) AS purchase_total
    FROM purchases
    WHERE deleted_at IS NULL AND shipment_id IS NOT NULL
    GROUP BY shipment_id
) p ON p.shipment_id = t.shipment_id;

-- 업체별 월 매출통계 — 매출통계 화면(업체별 탭)이 그대로 쓰는 뷰
CREATE OR REPLACE VIEW v_sales_by_company_month AS
SELECT
    t.business_entity_id,
    DATE_FORMAT(t.voucher_date, '%Y-%m') AS ym,
    t.company_id,
    co.name_ko            AS company_name,
    COUNT(*)              AS awb_count,
    SUM(t.charge_weight)  AS total_weight,
    SUM(t.zero_supply)    AS zero_supply,
    SUM(t.taxable_supply) AS taxable_supply,
    SUM(t.tax_total)      AS vat,
    SUM(t.grand_total)    AS sales_total
FROM v_shipment_totals t
JOIN companies co ON co.id = t.company_id
GROUP BY t.business_entity_id, ym, t.company_id;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
--  남은 것 : 11_schema_billing.sql
--    invoices / invoice_shipments / tax_invoices / tax_invoice_items /
--    payments / documents / boards / posts / post_files /
--    quotations / quotation_items / transaction_statements
-- ============================================================================


-- ==========================================================================
--  << 11_schema_billing.sql >>
-- ==========================================================================

-- ============================================================================
--  GOODPOST 통합 업무관리 시스템 — 신규 스키마 (영업문서 · 회계 · 문서 · 게시판)
--  10_schema_core.sql 을 먼저 실행한 뒤 이 파일을 실행합니다.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
--  7. 견적서 (스펙 [12][13])
--  기존 시스템에 견적서 기능이 없습니다. IS_ESTIMATE 는 홈페이지 견적문의 게시판이고
--  실제 견적서는 엑셀로 만들고 있었습니다. 신규는 프로그램 안에서 만듭니다.
-- ============================================================================

CREATE TABLE quotations (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_entity_id BIGINT UNSIGNED NOT NULL,
  quote_no           VARCHAR(40) NOT NULL              COMMENT 'GPA-Q-202609-001',
  company_id         BIGINT UNSIGNED NULL              COMMENT 'NULL = 미등록 신규 문의',
  contact_id         BIGINT UNSIGNED NULL,
  prospect_name      VARCHAR(150) NULL                 COMMENT '거래처 미등록 시 상호를 직접 입력',
  quote_date         DATE NOT NULL,
  valid_until        DATE NULL,
  trade_type         VARCHAR(10) NULL                  COMMENT 'EXPORT / IMPORT',
  carrier_id         BIGINT UNSIGNED NULL,
  origin_country     CHAR(2) NULL,
  dest_country       CHAR(2) NULL,
  charge_weight      DECIMAL(10,2) NULL,
  supply_total       DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_total          DECIMAL(15,2) NOT NULL DEFAULT 0,
  grand_total        DECIMAL(15,2) NOT NULL DEFAULT 0,
  status             VARCHAR(20) NOT NULL DEFAULT 'DRAFT'
                     COMMENT 'DRAFT/SENT/ACCEPTED/REJECTED/EXPIRED',
  converted_shipment_id BIGINT UNSIGNED NULL           COMMENT '수주 시 생성된 매출전표',
  terms              TEXT NULL                         COMMENT '견적 조건 문구',
  remark             TEXT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by         BIGINT UNSIGNED NULL,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at         DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_quote_no (business_entity_id, quote_no),
  KEY ix_quote_company (company_id, quote_date),
  KEY ix_quote_status (status, quote_date),
  CONSTRAINT fk_quote_entity  FOREIGN KEY (business_entity_id) REFERENCES business_entities(id),
  CONSTRAINT fk_quote_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_quote_ship    FOREIGN KEY (converted_shipment_id) REFERENCES shipments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='견적서';

CREATE TABLE quotation_items (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  quotation_id  BIGINT UNSIGNED NOT NULL,
  line_no       SMALLINT NOT NULL,
  charge_type   VARCHAR(30) NOT NULL,
  item_name     VARCHAR(200) NOT NULL,
  qty           DECIMAL(12,2) NOT NULL DEFAULT 1,
  unit_price    DECIMAL(15,2) NOT NULL DEFAULT 0,
  supply_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_type      VARCHAR(10) NOT NULL DEFAULT 'ZERO',
  tax_rate      DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  tax_amount    DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_amount  DECIMAL(15,2) NOT NULL DEFAULT 0,
  remark        VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_qitem (quotation_id, line_no),
  CONSTRAINT fk_qitem_quote FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='견적 항목';


-- ============================================================================
--  8. 거래명세서 (스펙 [14][15])
-- ============================================================================

CREATE TABLE statements (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_entity_id BIGINT UNSIGNED NOT NULL,
  statement_no       VARCHAR(40) NOT NULL              COMMENT 'GPA-S-202609-001',
  company_id         BIGINT UNSIGNED NOT NULL,
  statement_date     DATE NOT NULL,
  period_from        DATE NULL,
  period_to          DATE NULL,
  supply_total       DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_total          DECIMAL(15,2) NOT NULL DEFAULT 0,
  grand_total        DECIMAL(15,2) NOT NULL DEFAULT 0,
  status             VARCHAR(20) NOT NULL DEFAULT 'DRAFT' COMMENT 'DRAFT/ISSUED/SENT',
  remark             TEXT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by         BIGINT UNSIGNED NULL,
  deleted_at         DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_stmt_no (business_entity_id, statement_no),
  KEY ix_stmt_company (company_id, statement_date),
  CONSTRAINT fk_stmt_entity  FOREIGN KEY (business_entity_id) REFERENCES business_entities(id),
  CONSTRAINT fk_stmt_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='거래명세서';

CREATE TABLE statement_shipments (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  statement_id BIGINT UNSIGNED NOT NULL,
  shipment_id  BIGINT UNSIGNED NOT NULL,
  line_no      SMALLINT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ss (statement_id, shipment_id),
  KEY ix_ss_ship (shipment_id),
  CONSTRAINT fk_ss_stmt FOREIGN KEY (statement_id) REFERENCES statements(id) ON DELETE CASCADE,
  CONSTRAINT fk_ss_ship FOREIGN KEY (shipment_id) REFERENCES shipments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='거래명세서 수록 전표';


-- ============================================================================
--  9. 청구서 · 인보이스 (스펙 [30][31])
--  기존엔 sp_INVOICE_sum / sp_IS_INVOICE_LSTCNT_SEL 로 IS_SALES 를 직접 집계했고
--  청구서 자체를 저장하지 않았습니다. 신규는 청구 단위를 테이블로 확정합니다.
-- ============================================================================

CREATE TABLE invoices (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_entity_id BIGINT UNSIGNED NOT NULL,
  invoice_no         VARCHAR(40) NOT NULL              COMMENT 'GPA-INV-202609-001',
  company_id         BIGINT UNSIGNED NOT NULL,
  invoice_date       DATE NOT NULL,
  period_from        DATE NOT NULL                     COMMENT '청구 대상기간 시작',
  period_to          DATE NOT NULL,
  due_date           DATE NULL,
  zero_supply        DECIMAL(15,2) NOT NULL DEFAULT 0  COMMENT '영세율 공급가액',
  taxable_supply     DECIMAL(15,2) NOT NULL DEFAULT 0  COMMENT '과세 공급가액',
  exempt_supply      DECIMAL(15,2) NOT NULL DEFAULT 0  COMMENT '면세 공급가액',
  tax_total          DECIMAL(15,2) NOT NULL DEFAULT 0  COMMENT 'VAT',
  grand_total        DECIMAL(15,2) NOT NULL DEFAULT 0,
  paid_amount        DECIMAL(15,2) NOT NULL DEFAULT 0  COMMENT '수금 합계 (payments 로 갱신)',
  balance            DECIMAL(15,2) NOT NULL DEFAULT 0  COMMENT '미수금 = grand_total - paid_amount',
  status             VARCHAR(20) NOT NULL DEFAULT 'DRAFT'
                     COMMENT 'DRAFT/ISSUED/PARTIAL/PAID/OVERDUE/CANCELLED',
  issued_at          DATETIME NULL                     COMMENT '마지막 발행(재발행) 시각',
  issue_count        INT UNSIGNED NOT NULL DEFAULT 0   COMMENT '발행 횟수 (2 이상이면 재발행됨)',
  reissue_reason     VARCHAR(255) NULL                 COMMENT '마지막 재발행 사유',
  changed_at         DATETIME NULL                     COMMENT '발행 뒤 내용이 바뀐 시각 (재발행 필요 표시용)',
  bank_account_id    BIGINT UNSIGNED NULL              COMMENT '인보이스에 찍을 입금계좌',
  remark             TEXT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by         BIGINT UNSIGNED NULL,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at         DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inv_no (business_entity_id, invoice_no),
  KEY ix_inv_company (company_id, invoice_date),
  KEY ix_inv_status (status, due_date),
  KEY ix_inv_period (business_entity_id, period_from, period_to),
  CONSTRAINT fk_inv_entity  FOREIGN KEY (business_entity_id) REFERENCES business_entities(id),
  CONSTRAINT fk_inv_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_inv_bank    FOREIGN KEY (bank_account_id) REFERENCES business_bank_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='청구서';

-- 어떤 전표가 어느 청구서에 들어갔는지. UNIQUE 로 이중청구를 구조적으로 막습니다.
CREATE TABLE invoice_shipments (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id  BIGINT UNSIGNED NOT NULL,
  shipment_id BIGINT UNSIGNED NOT NULL,
  line_no     SMALLINT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_is_ship (shipment_id),
  KEY ix_is_invoice (invoice_id),
  CONSTRAINT fk_is_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_is_ship    FOREIGN KEY (shipment_id) REFERENCES shipments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='청구서 수록 전표 (1전표 1청구)';

-- 전표에 안 붙는 별도 청구 항목 (조정 · 할인 · 지연료)
CREATE TABLE invoice_items (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id    BIGINT UNSIGNED NOT NULL,
  line_no       SMALLINT NOT NULL,
  item_name     VARCHAR(200) NOT NULL,
  supply_amount DECIMAL(15,2) NOT NULL DEFAULT 0       COMMENT '음수 = 차감',
  tax_type      VARCHAR(10) NOT NULL DEFAULT 'TAXABLE',
  tax_amount    DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_amount  DECIMAL(15,2) NOT NULL DEFAULT 0,
  remark        VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_iitem (invoice_id, line_no),
  CONSTRAINT fk_iitem_inv FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='청구서 조정 항목';


-- ============================================================================
--  10. 세금계산서 (스펙 [32])
--  기존 IS_TAXBILL 은 61컬럼에 Items1~Items4 / Price1~4 / Tax1~4 가 고정으로 박혀
--  품목이 5개면 발행할 수 없었습니다. 행으로 분해합니다.
-- ============================================================================

CREATE TABLE tax_invoices (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_entity_id BIGINT UNSIGNED NOT NULL,
  doc_no             VARCHAR(40) NOT NULL              COMMENT '내부 관리번호',
  nts_approval_no    VARCHAR(40) NULL                  COMMENT '전자세금계산서 국세청 승인번호',
  company_id         BIGINT UNSIGNED NOT NULL,
  invoice_id         BIGINT UNSIGNED NULL              COMMENT '어느 청구서로 발행했는지',
  issue_date         DATE NOT NULL                     COMMENT '작성일자',
  doc_type           VARCHAR(20) NOT NULL DEFAULT 'TAX'
                     COMMENT 'TAX 세금계산서 / EXEMPT 계산서 / MODIFY 수정',
  modify_reason      VARCHAR(30) NULL                  COMMENT '수정 사유 코드',
  original_id        BIGINT UNSIGNED NULL              COMMENT '수정 대상 원본',
  -- 공급받는자 스냅샷 : 거래처 정보가 나중에 바뀌어도 발행본은 불변이어야 합니다
  buyer_biz_no       VARCHAR(20)  NOT NULL,
  buyer_name         VARCHAR(150) NOT NULL,
  buyer_rep          VARCHAR(50)  NULL,
  buyer_address      VARCHAR(255) NULL,
  buyer_biz_type     VARCHAR(50)  NULL,
  buyer_biz_item     VARCHAR(100) NULL,
  buyer_email        VARCHAR(100) NULL,
  supply_total       DECIMAL(15,2) NOT NULL DEFAULT 0,
  tax_total          DECIMAL(15,2) NOT NULL DEFAULT 0,
  grand_total        DECIMAL(15,2) NOT NULL DEFAULT 0,
  status             VARCHAR(20) NOT NULL DEFAULT 'DRAFT'
                     COMMENT 'DRAFT/ISSUED/SENT/CANCELLED/FAILED',
  issued_at          DATETIME NULL                     COMMENT '발행 처리 시각',
  revision           INT UNSIGNED NOT NULL DEFAULT 1   COMMENT '같은 청구서의 몇 번째 발행본인지 (1 = 처음)',
  reissue_reason     VARCHAR(255) NULL                 COMMENT '재발행 · 수정발행 사유',
  replaced_by        BIGINT UNSIGNED NULL              COMMENT '이 문서를 대체(재발행 · 수정)한 새 문서 id',
  sent_at            DATETIME NULL,
  remark             VARCHAR(255) NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by         BIGINT UNSIGNED NULL,
  deleted_at         DATETIME NULL,
  legacy_idx         INT NULL                          COMMENT '기존 IS_TAXBILL 식별자',
  PRIMARY KEY (id),
  UNIQUE KEY uq_tax_no (business_entity_id, doc_no),
  KEY ix_tax_company (company_id, issue_date),
  KEY ix_tax_invoice (invoice_id),
  KEY ix_tax_status (status, issue_date),
  CONSTRAINT fk_tax_entity  FOREIGN KEY (business_entity_id) REFERENCES business_entities(id),
  CONSTRAINT fk_tax_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_tax_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id),
  CONSTRAINT fk_tax_orig    FOREIGN KEY (original_id) REFERENCES tax_invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='세금계산서';

CREATE TABLE tax_invoice_items (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tax_invoice_id BIGINT UNSIGNED NOT NULL,
  line_no        SMALLINT NOT NULL                    COMMENT '기존 Items1~4 의 1~4 가 여기로. 개수 제한 없음',
  supply_date    DATE NULL                            COMMENT '공급일',
  item_name      VARCHAR(200) NOT NULL                COMMENT '기존 Items1~4',
  spec           VARCHAR(50)  NULL                    COMMENT '규격',
  qty            DECIMAL(12,2) NULL,
  unit_price     DECIMAL(15,2) NULL,
  supply_amount  DECIMAL(15,2) NOT NULL DEFAULT 0     COMMENT '기존 Price1~4',
  tax_amount     DECIMAL(15,2) NOT NULL DEFAULT 0     COMMENT '기존 Tax1~4',
  remark         VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_titem (tax_invoice_id, line_no),
  CONSTRAINT fk_titem_tax FOREIGN KEY (tax_invoice_id) REFERENCES tax_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='세금계산서 품목';


-- ============================================================================
--  11. 수금 (스펙 [33]) — 기존 IS_PAYMENT
-- ============================================================================

CREATE TABLE payments (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_entity_id BIGINT UNSIGNED NOT NULL,
  company_id         BIGINT UNSIGNED NOT NULL,
  invoice_id         BIGINT UNSIGNED NULL              COMMENT 'NULL = 선수금 / 미배분 입금',
  paid_at            DATE NOT NULL,
  amount             DECIMAL(15,2) NOT NULL,
  method             VARCHAR(20) NOT NULL DEFAULT 'TRANSFER'
                     COMMENT 'TRANSFER 계좌이체 / CARD / CASH / NOTE 어음 / PG',
  bank_account_id    BIGINT UNSIGNED NULL              COMMENT '입금받은 자사 계좌',
  depositor          VARCHAR(100) NULL                 COMMENT '입금자명 — 업체명과 다른 경우가 많음',
  pg_provider        VARCHAR(30)  NULL                 COMMENT 'PG 사 — 추후 지정',
  pg_tid             VARCHAR(100) NULL,
  remark             VARCHAR(255) NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by         BIGINT UNSIGNED NULL,
  deleted_at         DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_pay_company (company_id, paid_at),
  KEY ix_pay_invoice (invoice_id),
  KEY ix_pay_date (business_entity_id, paid_at),
  CONSTRAINT fk_pay_entity  FOREIGN KEY (business_entity_id) REFERENCES business_entities(id),
  CONSTRAINT fk_pay_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_pay_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id),
  CONSTRAINT fk_pay_bank    FOREIGN KEY (bank_account_id) REFERENCES business_bank_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='수금';


-- ============================================================================
--  12. 문서보관함 (스펙 [38][39])
--  9종 : AWB · 인보이스 · 패킹리스트 · 원산지증명서 · 수입신고필증 ·
--        수출신고필증 · 수출청구서 · 수입청구서 · 통관정산서
--  파일 본체는 서버에 저장하고 NAS 로 자동 백업합니다. DB 는 경로만 들고 있습니다.
-- ============================================================================

CREATE TABLE document_types (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code       VARCHAR(30)  NOT NULL,
  name       VARCHAR(50)  NOT NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_doctype (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='문서 종류';

INSERT INTO document_types (code, name, sort_order) VALUES
  ('AWB',            'AWB 운송장',   1),
  ('INVOICE',        '인보이스',      2),
  ('PACKING_LIST',   '패킹리스트',    3),
  ('CO',             '원산지증명서',  4),
  ('IMPORT_DECL',    '수입신고필증',  5),
  ('EXPORT_DECL',    '수출신고필증',  6),
  ('EXPORT_BILL',    '수출청구서',    7),
  ('IMPORT_BILL',    '수입청구서',    8),
  ('CUSTOMS_SETTLE', '통관정산서',    9);

CREATE TABLE documents (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_entity_id BIGINT UNSIGNED NOT NULL,
  document_type_id   BIGINT UNSIGNED NOT NULL,
  shipment_id        BIGINT UNSIGNED NULL              COMMENT 'AWB 에 매달린 문서',
  company_id         BIGINT UNSIGNED NULL,
  doc_date           DATE NULL,
  title              VARCHAR(255) NOT NULL,
  original_name      VARCHAR(255) NOT NULL,
  stored_path        VARCHAR(500) NOT NULL             COMMENT '서버 기준 경로 (원본)',
  mime_type          VARCHAR(100) NULL,
  size_bytes         BIGINT UNSIGNED NULL,
  checksum_sha256    CHAR(64) NULL                     COMMENT 'NAS 백업 검증용',
  backup_status      VARCHAR(20) NOT NULL DEFAULT 'PENDING'
                     COMMENT 'PENDING/SYNCED/FAILED — NAS 백업 상태',
  backup_path        VARCHAR(500) NULL,
  backup_at          DATETIME NULL,
  uploaded_by        BIGINT UNSIGNED NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at         DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_doc_ship (shipment_id, document_type_id),
  KEY ix_doc_company (company_id, doc_date),
  KEY ix_doc_type (document_type_id, doc_date),
  KEY ix_doc_backup (backup_status),
  CONSTRAINT fk_doc_entity FOREIGN KEY (business_entity_id) REFERENCES business_entities(id),
  CONSTRAINT fk_doc_type   FOREIGN KEY (document_type_id) REFERENCES document_types(id),
  CONSTRAINT fk_doc_ship   FOREIGN KEY (shipment_id) REFERENCES shipments(id),
  CONSTRAINT fk_doc_comp   FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='문서보관함';

-- 저장소 설정 (스펙 [39]) — SFTP 가 기본. FTP 는 비밀번호가 평문으로 흐릅니다
CREATE TABLE storage_settings (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name           VARCHAR(50)  NOT NULL                 COMMENT 'iptime NAS / ipDISK',
  storage_type   VARCHAR(20)  NOT NULL DEFAULT 'SFTP'  COMMENT 'LOCAL / SFTP / SMB / S3',
  host           VARCHAR(100) NULL,
  port           SMALLINT     NULL DEFAULT 22,
  username       VARCHAR(50)  NULL,
  secret_enc     VARBINARY(512) NULL                   COMMENT '암호화 저장. 평문 금지',
  base_path      VARCHAR(500) NULL,
  role           VARCHAR(20)  NOT NULL DEFAULT 'BACKUP' COMMENT 'PRIMARY 원본 / BACKUP 백업',
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  last_test_at   DATETIME NULL,
  last_test_ok   TINYINT(1) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_storage_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='저장소 설정';


-- ============================================================================
--  13. 홈페이지 게시판 (스펙 [40][41])
--  기존 IS_BOARDSINFO / IS_QNA / IS_QNA_ANSWER / IS_Counsel / IS_ESTIMATE /
--       IS_FILEBOARD* 를 하나의 구조로 통합
-- ============================================================================

CREATE TABLE boards (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(30) NOT NULL                  COMMENT 'NOTICE / QNA / COUNSEL / ESTIMATE / FILE',
  name          VARCHAR(50) NOT NULL,
  board_type    VARCHAR(20) NOT NULL DEFAULT 'NORMAL' COMMENT 'NORMAL / QNA 답변형 / INQUIRY 문의형',
  use_file      TINYINT(1) NOT NULL DEFAULT 1,
  use_secret    TINYINT(1) NOT NULL DEFAULT 0,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  sort_order    SMALLINT NOT NULL DEFAULT 0,
  legacy_source VARCHAR(30) NULL                      COMMENT '어느 기존 테이블에서 왔는지',
  PRIMARY KEY (id),
  UNIQUE KEY uq_board_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='게시판';

CREATE TABLE posts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  board_id      BIGINT UNSIGNED NOT NULL,
  parent_id     BIGINT UNSIGNED NULL                  COMMENT '답변글 — 기존 IS_QNA_ANSWER 를 흡수',
  title         VARCHAR(255) NOT NULL,
  content       MEDIUMTEXT NULL,
  writer_name   VARCHAR(50) NOT NULL,
  writer_email  VARCHAR(100) NULL,
  writer_phone  VARCHAR(30)  NULL,
  writer_ip     VARCHAR(45)  NULL,
  is_secret     TINYINT(1) NOT NULL DEFAULT 0,
  is_answered   TINYINT(1) NOT NULL DEFAULT 0,
  view_count    INT UNSIGNED NOT NULL DEFAULT 0,
  answered_by   BIGINT UNSIGNED NULL,
  answered_at   DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at    DATETIME NULL,
  legacy_idx    INT NULL,
  PRIMARY KEY (id),
  KEY ix_post_board (board_id, created_at),
  KEY ix_post_parent (parent_id),
  KEY ix_post_answered (board_id, is_answered),
  CONSTRAINT fk_post_board  FOREIGN KEY (board_id) REFERENCES boards(id),
  CONSTRAINT fk_post_parent FOREIGN KEY (parent_id) REFERENCES posts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='게시글';

CREATE TABLE post_files (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id        BIGINT UNSIGNED NOT NULL,
  original_name  VARCHAR(255) NOT NULL,
  stored_path    VARCHAR(500) NOT NULL,
  mime_type      VARCHAR(100) NULL,
  size_bytes     BIGINT UNSIGNED NULL,
  download_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_pf_post (post_id),
  CONSTRAINT fk_pf_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='게시글 첨부';


-- ============================================================================
--  14. 시스템 — 권한 (스펙 [44]) · 문서번호 채번 (스펙 [46])
-- ============================================================================

CREATE TABLE permissions (
  id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code     VARCHAR(60) NOT NULL                       COMMENT 'sales.voucher.write',
  group_ko VARCHAR(30) NOT NULL                       COMMENT '영업관리',
  name_ko  VARCHAR(60) NOT NULL                       COMMENT '매출전표 등록/수정',
  PRIMARY KEY (id),
  UNIQUE KEY uq_perm (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='권한 항목';

CREATE TABLE role_permissions (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  role_code     VARCHAR(30) NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rp (role_code, permission_id),
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='역할별 권한';

-- 사업자 · 문서종류 · 연월별 카운터.
-- 동시 발행 충돌을 막으려면 UPDATE ... SET last_seq = LAST_INSERT_ID(last_seq+1) 로
-- 행을 잠근 뒤 LAST_INSERT_ID() 를 읽습니다. SELECT MAX()+1 은 쓰지 않습니다.
CREATE TABLE doc_sequences (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_entity_id BIGINT UNSIGNED NOT NULL,
  doc_kind           VARCHAR(20) NOT NULL              COMMENT 'QUOTE/STMT/INVOICE/AWB/TAX',
  yyyymm             CHAR(6) NOT NULL,
  last_seq           INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_seq (business_entity_id, doc_kind, yyyymm),
  CONSTRAINT fk_seq_entity FOREIGN KEY (business_entity_id) REFERENCES business_entities(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='문서번호 채번';

SET FOREIGN_KEY_CHECKS = 1;


-- ==========================================================================
--  << 12_seed.sql >>
-- ==========================================================================

-- ============================================================================
--  기준 데이터 (seed)
--  실행 순서 : 10 → 11 → **12(이 파일)** → 13(거래처) → 14(매출전표)
--
--  13·14 번이 이 데이터를 전제로 합니다.
--   - business_entities.code = 'GPA'  (전표의 사업자)
--   - carriers.code = 'DHL' / 'FEDEX' / 'UPS' / 'EMS'  (할인율·운송사 연결)
--
--  이 파일에는 **비밀번호를 넣지 않습니다.** 관리자 계정은 아래 15번 참조.
-- ============================================================================

SET NAMES utf8mb4;

-- ============================================================================
--  1. 사업자
--  (주)굿배송항공 = 확정된 정보. 굿포스트미디어 = 아직 못 받은 항목이 NULL 입니다.
-- ============================================================================

INSERT INTO business_entities (
  code, name_ko, name_en, business_number, corp_number,
  representative, doc_manager, business_type, business_item,
  zipcode, address_ko, address_en, phone, fax, email,
  doc_prefix_quote, doc_prefix_stmt, doc_prefix_invoice, is_active
) VALUES
('GPA', '(주)굿배송항공', 'GOODPOST AIR CO., LTD.', '799-87-00630', NULL,
 '김화연', '이창호', '서비스', '국제물류주선업',
 NULL, '서울특별시 강서구 개화대로7길 4-17',
 '4-17, Gaehwa-daero 7-gil, Gangseo-gu, Seoul, Korea',
 '02-6929-0666', '02-6929-0667', NULL,
 'GPA-Q-', 'GPA-S-', 'GPA-INV-', 1),
-- 굿포스트미디어 : 사업자번호·주소·전화·계좌를 아직 못 받았습니다.
-- 값을 받으면 UPDATE 하시면 되고, 그때까지 이 사업자로는 전표를 만들지 않습니다(is_active=0)
('GPM', '굿포스트미디어', 'GOODPOST MEDIA', 'TBD-굿포스트미디어', NULL,
 '김화연', '이창호', NULL, '학술행사 · 출판',
 NULL, NULL, NULL, NULL, NULL, NULL,
 'GPM-Q-', 'GPM-S-', 'GPM-INV-', 0);

-- 입금계좌 — 인보이스 하단에 두 개가 함께 찍힙니다
INSERT INTO business_bank_accounts
  (business_entity_id, bank_name, account_no, account_holder, sort_order, is_active)
SELECT id, '국민은행', '459601-01-584168', '(주)굿배송항공', 1, 1
  FROM business_entities WHERE code = 'GPA'
UNION ALL
SELECT id, '신한은행', '140-011-790354',   '(주)굿배송항공', 2, 1
  FROM business_entities WHERE code = 'GPA';


-- ============================================================================
--  2. 운송사
--  TNT 는 만들지 않습니다 — FedEx 로 전환됐고 과거 전표의 TNT 도 FEDEX 로 승계합니다.
--  (운송사 미확인) 은 14번에서 자동 생성되므로 여기서는 만들지 않습니다.
-- ============================================================================

INSERT INTO carriers (code, name, tracking_enabled, default_tax_type, sort_order, is_active) VALUES
('DHL',   'DHL Express',    1, 'ZERO', 1, 1),
('FEDEX', 'FedEx',          1, 'ZERO', 2, 1),
('UPS',   'UPS',            1, 'ZERO', 3, 1),
('EMS',   'EMS (우체국)',    1, 'ZERO', 4, 1);

INSERT INTO carrier_services (carrier_id, code, name, is_active)
SELECT c.id, v.code, v.name, 1 FROM carriers c
JOIN (
  SELECT 'DHL'   AS cc, 'DHL-EW'  AS code, 'EXPRESS WORLDWIDE'   AS name UNION ALL
  SELECT 'DHL',         'DHL-ED',        'ECONOMY SELECT'              UNION ALL
  SELECT 'FEDEX',       'FDX-IP',        'INTERNATIONAL PRIORITY'      UNION ALL
  SELECT 'FEDEX',       'FDX-IE',        'INTERNATIONAL ECONOMY'       UNION ALL
  SELECT 'UPS',         'UPS-WE',        'WORLDWIDE EXPRESS'           UNION ALL
  SELECT 'UPS',         'UPS-WS',        'WORLDWIDE SAVER'             UNION ALL
  SELECT 'EMS',         'EMS-STD',       'EMS 국제특급'                 UNION ALL
  SELECT 'EMS',         'EMS-PRE',       'EMS 프리미엄'
) v ON v.cc = c.code;


-- ============================================================================
--  3. 유류할증료 — 현재 적용분만 자리를 잡아둡니다
--  실제 요율은 계약서/운송사 공지로 확인해 입력하세요. 값은 예시가 아니라 0 입니다.
--  calc_basis 는 '할인후 운임 기준' 이 기본입니다 — 계약서와 다르면 금액이 달라집니다
-- ============================================================================

INSERT INTO fuel_surcharges (carrier_id, fuel_rate, calc_basis, effective_from)
SELECT id, 0.00, 'AFTER_DISCOUNT', '2026-01-01' FROM carriers WHERE code IN ('DHL','FEDEX','UPS','EMS');


-- ============================================================================
--  4. 게시판
-- ============================================================================

INSERT INTO boards (code, name, board_type, use_file, use_secret, sort_order, legacy_source) VALUES
('NOTICE',   '공지사항',   'NORMAL',  1, 0, 1, 'IS_BOARDSINFO'),
('QNA',      '문의게시판', 'QNA',     1, 1, 2, 'IS_QNA'),
('COUNSEL',  '상담신청',   'INQUIRY', 0, 1, 3, 'IS_Counsel'),
('ESTIMATE', '견적문의',   'INQUIRY', 1, 1, 4, 'IS_ESTIMATE'),
('FILE',     '자료실',     'NORMAL',  1, 0, 5, 'IS_FILEBOARD');


-- ============================================================================
--  5. 저장소 — 서버가 원본, NAS 가 백업 (스펙 [39])
--  호스트·계정은 서버 구축 후 채웁니다. 비밀번호는 secret_enc 에 암호화해 넣고
--  이 파일에는 적지 않습니다. FTP 가 아니라 SFTP 입니다.
-- ============================================================================

INSERT INTO storage_settings (name, storage_type, base_path, role, is_active) VALUES
('서버 로컬',      'LOCAL', '/var/www/goodpost/storage/documents', 'PRIMARY', 1),
('iptime NAS',    'SFTP',  NULL,                                  'BACKUP',  0),
('ipDISK',        'SFTP',  NULL,                                  'BACKUP',  0);


-- ============================================================================
--  6. 권한 항목 (스펙 [44])
-- ============================================================================

INSERT INTO permissions (code, group_ko, name_ko) VALUES
-- 기준정보
('master.company.read',    '기준정보', '거래처 조회'),
('master.company.write',   '기준정보', '거래처 등록/수정'),
('master.company.delete',  '기준정보', '거래처 삭제(비활성)'),
('master.carrier.read',    '기준정보', '운송사 조회'),
('master.carrier.write',   '기준정보', '운송사 등록/수정'),
('master.rate.read',       '기준정보', '단가표 조회'),
('master.rate.write',      '기준정보', '단가표 등록/수정'),
('master.discount.write',  '기준정보', '할인율 수정'),
('master.fuel.write',      '기준정보', '유류할증료 수정'),
-- 영업관리
('sales.quote.read',       '영업관리', '견적서 조회'),
('sales.quote.write',      '영업관리', '견적서 작성/수정'),
('sales.statement.read',   '영업관리', '거래명세서 조회'),
('sales.statement.write',  '영업관리', '거래명세서 작성'),
('sales.voucher.read',     '영업관리', '매출전표 조회'),
('sales.voucher.write',    '영업관리', '매출전표 등록/수정'),
('sales.voucher.approve',  '영업관리', '매출전표 승인'),
('sales.voucher.cancel',   '영업관리', '매출전표 취소'),
('sales.stats.read',       '영업관리', '매출통계 조회'),
('sales.stats.export',     '영업관리', '매출통계 엑셀 다운로드'),
('sales.calc.use',         '영업관리', '단가계산기 사용'),
-- 물류관리
('logi.awb.read',          '물류관리', 'AWB 조회'),
('logi.awb.write',         '물류관리', 'AWB 발행/라벨출력'),
('logi.tracking.read',     '물류관리', '배송추적 조회'),
('logi.document.read',     '물류관리', '문서보관함 조회'),
('logi.document.write',    '물류관리', '문서 업로드/삭제'),
-- 회계관리
('acct.invoice.read',      '회계관리', '청구서 조회'),
('acct.invoice.write',     '회계관리', '청구서 작성/수정'),
('acct.tax.read',          '회계관리', '세금계산서 조회'),
('acct.tax.issue',         '회계관리', '세금계산서 발행'),
('acct.payment.read',      '회계관리', '수금 조회'),
('acct.payment.write',     '회계관리', '수금 등록/수정'),
('acct.purchase.read',     '회계관리', '매입 조회'),
('acct.purchase.write',    '회계관리', '매입 등록/수정'),
('acct.profit.read',       '회계관리', '손익 조회'),
-- 홈페이지
('web.board.read',         '홈페이지', '게시판 조회'),
('web.board.write',        '홈페이지', '게시글 작성/답변'),
('web.board.delete',       '홈페이지', '게시글 삭제'),
-- 시스템
('sys.admin.read',         '시스템',   '관리자 조회'),
('sys.admin.write',        '시스템',   '관리자 등록/수정'),
('sys.permission.write',   '시스템',   '권한 설정'),
('sys.log.read',           '시스템',   '작업로그 조회'),
('sys.entity.write',       '시스템',   '사업자정보 수정'),
('sys.storage.write',      '시스템',   '저장소 설정');

-- ----------------------------------------------------------------------------
--  역할별 기본 권한
--  SUPER_ADMIN 전부 / MANAGER 삭제·권한설정 제외 / 나머지는 담당 영역
-- ----------------------------------------------------------------------------

INSERT INTO role_permissions (role_code, permission_id)
SELECT 'SUPER_ADMIN', id FROM permissions;

INSERT INTO role_permissions (role_code, permission_id)
SELECT 'MANAGER', id FROM permissions
WHERE code NOT IN ('sys.permission.write', 'master.company.delete', 'sys.entity.write');

INSERT INTO role_permissions (role_code, permission_id)
SELECT 'SALES', id FROM permissions
WHERE group_ko IN ('기준정보', '영업관리')
  AND code NOT IN ('master.company.delete', 'master.rate.write',
                   'master.discount.write', 'master.fuel.write',
                   'sales.voucher.approve', 'sales.voucher.cancel');

INSERT INTO role_permissions (role_code, permission_id)
SELECT 'LOGISTICS', id FROM permissions
WHERE group_ko = '물류관리'
   OR code IN ('master.company.read', 'master.carrier.read',
               'sales.voucher.read', 'sales.voucher.write');

INSERT INTO role_permissions (role_code, permission_id)
SELECT 'ACCOUNTING', id FROM permissions
WHERE group_ko = '회계관리'
   OR code IN ('master.company.read', 'sales.voucher.read',
               'sales.stats.read', 'sales.stats.export');

INSERT INTO role_permissions (role_code, permission_id)
SELECT 'BOARD_MANAGER', id FROM permissions WHERE group_ko = '홈페이지';

INSERT INTO role_permissions (role_code, permission_id)
SELECT 'VIEWER', id FROM permissions WHERE code LIKE '%.read';


-- ============================================================================
--  7. 관리자 계정 — **이 파일로 만들지 않습니다**
--
--  비밀번호를 SQL 파일에 적으면 백업·git·화면 어디에든 평문으로 남습니다.
--  첫 관리자는 앱에서 만듭니다.
--
--      php artisan goodpost:create-admin
--
--  명령이 login_id 와 비밀번호를 물어보고 password_hash() 로 저장합니다.
--
--  기존 IS_MEMBER 계정을 옮길 때도 **비밀번호는 옮기지 않습니다.**
--  기존 저장 방식이 안전한지 확인할 수 없으므로 전원 재설정합니다.
--  아이디·이름·역할만 옮기고 is_active = 0 으로 둔 뒤, 본인이 비밀번호를
--  설정하면 활성화하는 순서가 안전합니다.
-- ============================================================================

-- 참고 : IS_MEMBER 이관 시 쓸 형태 (비밀번호 없음, 비활성 상태로 생성)
-- INSERT INTO admins (login_id, password_hash, name, role_code, is_active)
-- SELECT TRIM(m.ID), '!RESET_REQUIRED!', TRIM(m.NAME), 'VIEWER', 0
-- FROM admins_staging m;


-- ============================================================================
--  8. 확인
-- ============================================================================

SELECT '사업자'   AS 항목, COUNT(*) AS 건수 FROM business_entities
UNION ALL SELECT '입금계좌', COUNT(*) FROM business_bank_accounts
UNION ALL SELECT '운송사',   COUNT(*) FROM carriers
UNION ALL SELECT '서비스',   COUNT(*) FROM carrier_services
UNION ALL SELECT '문서종류', COUNT(*) FROM document_types
UNION ALL SELECT '게시판',   COUNT(*) FROM boards
UNION ALL SELECT '권한항목', COUNT(*) FROM permissions
UNION ALL SELECT '역할권한', COUNT(*) FROM role_permissions;

-- 13·14 번이 찾는 값이 있는지 확인 — 없으면 이관이 통째로 실패합니다
SELECT (SELECT COUNT(*) FROM business_entities WHERE code = 'GPA') AS GPA사업자_1이어야함,
       (SELECT COUNT(*) FROM carriers WHERE code IN ('DHL','FEDEX','UPS','EMS')) AS 운송사_4여야함;

-- ============================================================================
--  환경설정 저장소 (스펙 [48])
--  10~12 번 다음에 실행합니다. 기존 테이블을 건드리지 않는 추가분입니다.
--
--  세금·회계에 직접 영향을 주는 값(세율·문서번호·계좌·직인·세금계산서 API)은
--  여기가 아니라 business_entities 에 있습니다. 사업자마다 달라야 하기 때문입니다.
--  이 표에는 시스템 전체에 하나만 있으면 되는 값을 둡니다.
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key  VARCHAR(60)  NOT NULL,
  setting_val  VARCHAR(500) NULL,
  group_ko     VARCHAR(30)  NOT NULL DEFAULT '일반',
  label_ko     VARCHAR(80)  NOT NULL,
  help_ko      VARCHAR(255) NULL,
  input_type   VARCHAR(20)  NOT NULL DEFAULT 'text'
               COMMENT 'text / number / bool / select',
  options_csv  VARCHAR(255) NULL   COMMENT 'select 일 때 선택지',
  sort_order   SMALLINT     NOT NULL DEFAULT 0,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by   BIGINT UNSIGNED NULL,
  PRIMARY KEY (setting_key),
  KEY ix_as_group (group_ko, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='환경설정';

INSERT INTO app_settings
  (setting_key, setting_val, group_ko, label_ko, help_ko, input_type, options_csv, sort_order)
VALUES
  ('list_per_page',      '20',   '화면',   '목록 한 쪽에 보일 건수',
   '거래처·전표 목록에 적용됩니다.', 'number', NULL, 1),
  ('dashboard_recent',   '10',   '화면',   '대시보드 최근 전표 건수',
   NULL, 'number', NULL, 2),
  ('volume_divisor',     '5000', '물류',   '부피중량 제수',
   '가로×세로×높이 ÷ 이 값. 항공 표준은 5000 입니다.', 'number', NULL, 1),
  ('weight_round',       '0.5',  '물류',   '중량 올림 단위 (kg)',
   '단가계산기에서 청구중량을 이 단위로 올립니다. 0 이면 올리지 않습니다.',
   'select', '0,0.5,1', 2),
  ('default_due_days',   '30',   '회계',   '청구서 기본 지급기한 (일)',
   '청구서를 만들 때 지급기한 기본값입니다.', 'number', NULL, 1),
  ('vat_rate',           '10',   '회계',   '부가세율 (%)',
   '과세 항목에 적용됩니다. 법이 바뀌지 않는 한 10 입니다.', 'number', NULL, 2),
  ('overdue_warn_days',  '7',    '회계',   '연체 경고 시작 (일)',
   '지급기한이 지난 뒤 며칠부터 목록에서 붉게 표시할지.', 'number', NULL, 3),
  ('login_max_fail',     '5',    '보안',   '로그인 실패 허용 횟수',
   '이 횟수를 넘으면 계정이 잠깁니다.', 'number', NULL, 1),
  ('login_lock_min',     '10',   '보안',   '계정 잠금 시간 (분)',
   NULL, 'number', NULL, 2),
  ('session_idle_min',   '120',  '보안',   '자동 로그아웃 (분)',
   '이 시간 동안 조작이 없으면 로그아웃됩니다. 0 이면 끄기.', 'number', NULL, 3),
  ('log_keep_days',      '730',  '보안',   '작업로그 보관 기간 (일)',
   '금액·거래처 변경 이력이므로 넉넉히 두는 것을 권합니다.', 'number', NULL, 4)
ON DUPLICATE KEY UPDATE label_ko = VALUES(label_ko);

SELECT COUNT(*) AS 환경설정_항목수 FROM app_settings;


-- ============================================================
--  18. 입출금 · 미수금 · 거래처원장  (db/18_cash_management.sql)
-- ============================================================

-- ============================================================================
--  입출금 · 미수금 · 미지급금 · 거래처원장
--
--  실행 순서 : 10 → 11 → 12 → 17 → **18(이 파일)**
--
--  ────────────────────────────────────────────────────────────────────────
--  지정하신 테이블 목록과 이 파일의 대응
--  ────────────────────────────────────────────────────────────────────────
--   bank_accounts           → business_bank_accounts (기존 테이블 확장)
--        이미 invoices.bank_account_id 와 문서 출력이 이 테이블을 물고 있습니다.
--        새로 만들면 계좌가 두 군데 생깁니다. 컬럼만 더했습니다.
--   financial_transactions  → 신규. 입금·출금·계좌이체 한 테이블  ★
--   payment_allocations     → 신규. 입금 ↔ 매출전표 (N:N)         ★
--   expense_categories      → 신규. 관리자가 화면에서 편집
--   bank_imports            → 신규
--   bank_import_rows        → 신규
--   receivables             → **테이블이 아니라 뷰**로 만들었습니다.
--        미수금을 따로 저장해 두면, 입금을 고쳤는데 갱신을 한 번 빠뜨리는 순간
--        영원히 틀립니다. 볼 때 계산하면 언제나 맞습니다. (v_shipment_receivable)
--   transaction_attachments → 신규
--   financial_audit_logs    → 신규
--
--  ────────────────────────────────────────────────────────────────────────
--  폐기 : payments
--  ────────────────────────────────────────────────────────────────────────
--   기존 payments(수금)는 financial_transactions 로 흡수됩니다. 입금이 두 곳에
--   나뉘면 미수금이 갈라지기 때문입니다. 아직 한 건도 안 들어간 상태라 지금이
--   합칠 수 있는 유일한 시점입니다. 아래 12번에서 남아 있는 행을 옮기고
--   테이블에 폐기 표시를 남깁니다. (지우지는 않습니다)
--
--  ────────────────────────────────────────────────────────────────────────
--  손대지 않은 것 : companies · shipments · quotations · statements ·
--                   invoices · tax_invoices · purchases
--   견적 → 매출전표 → 거래명세서 → 세금계산서 흐름은 그대로입니다.
--
--  금액은 전부 DECIMAL(15,2) 입니다. FLOAT 는 쓰지 않습니다 —
--  0.1 + 0.2 가 0.3 이 아닌 타입으로 돈을 세면 합계가 1원씩 어긋납니다.
--
--  사업자 분리 : 모든 신규 테이블에 business_entity_id 를 답니다.
--   거래처(companies)는 두 법인이 공용이라 거기에는 넣지 않습니다.
--   원장·통계는 (사업자 + 거래처) 로 끊어서 봅니다.
-- ============================================================================


-- ============================================================================
--  1. 계좌 — 기존 business_bank_accounts 확장
-- ============================================================================

ALTER TABLE business_bank_accounts
  ADD COLUMN account_type    VARCHAR(20) NOT NULL DEFAULT 'BANK'
      COMMENT 'BANK 예금 / CARD 카드 / CASH 현금 / VIRTUAL 가상계좌' AFTER account_holder,
  ADD COLUMN purpose         VARCHAR(20) NOT NULL DEFAULT 'BOTH'
      COMMENT 'IN 입금전용 / OUT 지급전용 / BOTH 공용' AFTER account_type,
  ADD COLUMN opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0
      COMMENT '개시잔액 — 이 시스템을 쓰기 시작한 시점의 잔고' AFTER purpose,
  ADD COLUMN opened_on       DATE NULL
      COMMENT '개시일. 이 날짜 이후 거래만 잔액에 반영' AFTER opening_balance,
  ADD COLUMN memo            VARCHAR(255) NULL AFTER opened_on;


-- ============================================================================
--  2. 비용분류 — 관리자가 화면에서 추가·수정합니다
--     코드를 고쳐야 항목이 늘어나는 구조로 만들지 않습니다.
-- ============================================================================

CREATE TABLE expense_categories (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(30)  NOT NULL,
  name        VARCHAR(50)  NOT NULL,
  parent_id   BIGINT UNSIGNED NULL          COMMENT '대분류 아래 소분류',
  is_cogs     TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = 매출원가 / 0 = 판관비',
  default_tax VARCHAR(10) NOT NULL DEFAULT 'TAXABLE'
              COMMENT '이 분류를 고르면 부가세 기본값. TAXABLE / ZERO / EXEMPT',
  sort_order  SMALLINT NOT NULL DEFAULT 0,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  memo        VARCHAR(255) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_exp_code (code),
  KEY ix_exp_sort (is_active, sort_order),
  CONSTRAINT fk_exp_parent FOREIGN KEY (parent_id) REFERENCES expense_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='비용분류';

INSERT INTO expense_categories (code, name, is_cogs, default_tax, sort_order) VALUES
  ('COURIER',   '특송/운송비',   1, 'TAXABLE',  1),
  ('AIR',       '항공운임',      1, 'ZERO',     2),
  ('SEA',       '해상운임',      1, 'ZERO',     3),
  ('OUTSOURCE', '외주비',        1, 'TAXABLE',  4),
  ('EVENT',     '행사운영비',    0, 'TAXABLE',  5),
  ('EQUIPMENT', '장비/렌탈비',   0, 'TAXABLE',  6),
  ('RENT',      '임차료',        0, 'TAXABLE',  7),
  ('SUPPLY',    '소모품비',      0, 'TAXABLE',  8),
  ('LABOR',     '인건비',        0, 'EXEMPT',   9),
  ('TAX',       '세금/공과금',   0, 'EXEMPT',  10),
  ('ETC',       '기타',          0, 'TAXABLE', 99)
ON DUPLICATE KEY UPDATE name = VALUES(name);


-- ============================================================================
--  3. 입출금 — 입금 · 출금 · 계좌이체를 한 테이블에  ★
--
--  txn_type
--    IN       입금   — 거래처에서 받음. company_id 를 채웁니다
--    OUT      출금   — 비용 지급. category_id 를 채웁니다
--    TRANSFER 계좌이체 — 회사 통장 사이 이동. **비용도 매출도 아닙니다**
--
--  계좌이체가 통계에 새어들면 비용이 부풀어 오릅니다. 모든 집계 쿼리는
--  WHERE txn_type <> 'TRANSFER' 를 답니다. 이체는 from/to 두 계좌를 한 행에
--  들고 있어서, 행이 둘로 갈라져 한쪽만 집계되는 사고가 생기지 않습니다.
--
--  status
--    CONFIRMED 확정 / CANCELLED 취소(역분개)
--    삭제하지 않습니다. 취소하면 배분이 풀리고 미수금이 자동으로 되돌아옵니다.
-- ============================================================================

CREATE TABLE financial_transactions (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_entity_id BIGINT UNSIGNED NOT NULL,
  doc_no             VARCHAR(40) NULL       COMMENT 'GPA-R-202609-0001 / GPA-D- / GPA-T-',
  txn_type           VARCHAR(10) NOT NULL   COMMENT 'IN 입금 / OUT 출금 / TRANSFER 계좌이체',
  txn_date           DATE NOT NULL,

  -- 상대방
  company_id         BIGINT UNSIGNED NULL   COMMENT '거래처 — 입금은 필수, 출금은 선택',
  counterparty       VARCHAR(100) NULL      COMMENT '입금자명 / 받는 쪽. 거래처명과 다를 때가 많습니다',

  -- 계좌 : 입금은 to, 출금은 from, 이체는 둘 다
  from_account_id    BIGINT UNSIGNED NULL   COMMENT '나간 계좌 (OUT / TRANSFER)',
  to_account_id      BIGINT UNSIGNED NULL   COMMENT '들어온 계좌 (IN / TRANSFER)',
  method             VARCHAR(20) NOT NULL DEFAULT 'TRANSFER'
                     COMMENT 'TRANSFER 계좌이체 / CARD / CASH / NOTE 어음 / PG',

  -- 금액 : 공급가액 + 부가세 = 합계. 부가세 신고에 그대로 쓰려면 나뉘어 있어야 합니다
  supply_amount      DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT '공급가액',
  tax_type           VARCHAR(10) NOT NULL DEFAULT 'TAXABLE'
                     COMMENT 'TAXABLE 과세 / ZERO 영세 / EXEMPT 면세',
  vat_amount         DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT '부가세',
  amount             DECIMAL(15,2) NOT NULL           COMMENT '실제 움직인 돈 = 공급가액 + 부가세',
  fee                DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT '이체수수료 — 이체에서 이것만 비용입니다',

  -- 출금 전용
  category_id        BIGINT UNSIGNED NULL   COMMENT '비용분류 (OUT 필수)',
  payee_type         VARCHAR(20) NULL
                     COMMENT 'VENDOR 업체 / COMPANY 거래처환불 / EXPENSE 경비 / TAX 세금 / PAYROLL 급여 / OTHER',
  evidence_type      VARCHAR(20) NOT NULL DEFAULT 'NONE'
                     COMMENT 'TAX_INVOICE / INVOICE 계산서 / CARD / CASH_RCPT 현금영수증 / SIMPLE 간이영수증 / NONE',
  evidence_no        VARCHAR(50) NULL       COMMENT '승인번호 등',

  -- 연결
  tax_invoice_id     BIGINT UNSIGNED NULL   COMMENT '관련 세금계산서',
  bank_row_id        BIGINT UNSIGNED NULL   COMMENT '은행 거래내역에서 만든 경우',
  reversal_of        BIGINT UNSIGNED NULL   COMMENT '역분개 대상. 이 행은 반대부호 거래입니다',

  alloc_amount       DECIMAL(15,2) NOT NULL DEFAULT 0
                     COMMENT '전표·매입에 배분된 합계. 금액보다 작으면 나머지는 선수금/미배분',
  summary            VARCHAR(255) NULL      COMMENT '적요',
  memo               TEXT NULL,

  status             VARCHAR(20) NOT NULL DEFAULT 'CONFIRMED'
                     COMMENT 'CONFIRMED 확정 / CANCELLED 취소',
  cancelled_at       DATETIME NULL,
  cancelled_by       BIGINT UNSIGNED NULL,
  cancel_reason      VARCHAR(255) NULL,

  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by         BIGINT UNSIGNED NULL,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by         BIGINT UNSIGNED NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_ft_doc (business_entity_id, doc_no),
  KEY ix_ft_date (business_entity_id, txn_date, txn_type),
  KEY ix_ft_company (company_id, txn_date),
  KEY ix_ft_from (from_account_id, txn_date),
  KEY ix_ft_to (to_account_id, txn_date),
  KEY ix_ft_cat (category_id, txn_date),
  KEY ix_ft_status (status, txn_date),
  CONSTRAINT fk_ft_entity  FOREIGN KEY (business_entity_id) REFERENCES business_entities(id),
  CONSTRAINT fk_ft_company FOREIGN KEY (company_id)      REFERENCES companies(id),
  CONSTRAINT fk_ft_from    FOREIGN KEY (from_account_id) REFERENCES business_bank_accounts(id),
  CONSTRAINT fk_ft_to      FOREIGN KEY (to_account_id)   REFERENCES business_bank_accounts(id),
  CONSTRAINT fk_ft_cat     FOREIGN KEY (category_id)     REFERENCES expense_categories(id),
  CONSTRAINT fk_ft_taxinv  FOREIGN KEY (tax_invoice_id)  REFERENCES tax_invoices(id),
  CONSTRAINT fk_ft_rev     FOREIGN KEY (reversal_of)     REFERENCES financial_transactions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='입출금 — 입금/출금/계좌이체';


-- ============================================================================
--  4. 입금 배분 — 한 건의 입금을 여러 매출전표에 나눠 붙입니다  ★
--
--   1 입금 → N 전표,  1 전표 → N 입금  둘 다 됩니다.
--   amount 는 "이 입금 중 이 전표에 얼마를 충당했는가" 입니다.
--     · 한 입금의 배분 합계 <= 입금액    (남으면 선수금)
--     · 한 전표의 배분 합계 <= 전표 금액 (초과 배분은 막습니다)
--   둘 다 앱이 트랜잭션 안에서 검사합니다. 화면에서 계산하지 않습니다.
--
--   출금을 매입(purchases)에 붙일 때도 같은 표를 씁니다 — purchase_id.
-- ============================================================================

CREATE TABLE payment_allocations (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transaction_id BIGINT UNSIGNED NOT NULL   COMMENT 'financial_transactions.id',
  line_no        SMALLINT NOT NULL DEFAULT 1,
  shipment_id    BIGINT UNSIGNED NULL       COMMENT '매출전표에 충당 — 입금의 기본',
  invoice_id     BIGINT UNSIGNED NULL       COMMENT '청구서 단위로 받은 경우',
  purchase_id    BIGINT UNSIGNED NULL       COMMENT '매입 결제 — 출금일 때',
  amount         DECIMAL(15,2) NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by     BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pa_line (transaction_id, line_no),
  KEY ix_pa_txn (transaction_id),
  KEY ix_pa_ship (shipment_id),
  KEY ix_pa_invoice (invoice_id),
  KEY ix_pa_purchase (purchase_id),
  CONSTRAINT fk_pa_txn      FOREIGN KEY (transaction_id) REFERENCES financial_transactions(id),
  CONSTRAINT fk_pa_ship     FOREIGN KEY (shipment_id)    REFERENCES shipments(id),
  CONSTRAINT fk_pa_invoice  FOREIGN KEY (invoice_id)     REFERENCES invoices(id),
  CONSTRAINT fk_pa_purchase FOREIGN KEY (purchase_id)    REFERENCES purchases(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='입출금 배분 — 1입금 N전표 / 1전표 N입금';


-- ============================================================================
--  5. 첨부 · 이력
--
--  파일 본체는 기존 documents(문서보관함)에 둡니다. 저장 경로가 한 곳이어야
--  백업과 NAS 동기화가 갈라지지 않습니다. 여기는 연결만 합니다.
-- ============================================================================

CREATE TABLE transaction_attachments (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transaction_id BIGINT UNSIGNED NOT NULL,
  document_id    BIGINT UNSIGNED NOT NULL,
  kind           VARCHAR(20) NOT NULL DEFAULT 'EVIDENCE'
                 COMMENT 'EVIDENCE 증빙 / RECEIPT 영수증 / STATEMENT 정산서 / OTHER',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by     BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ta (transaction_id, document_id),
  KEY ix_ta_doc (document_id),
  CONSTRAINT fk_ta_txn FOREIGN KEY (transaction_id) REFERENCES financial_transactions(id),
  CONSTRAINT fk_ta_doc FOREIGN KEY (document_id)    REFERENCES documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='입출금 증빙 첨부';


-- 재무 데이터 전용 이력. 일반 activity_logs 보다 강하게 남깁니다 —
-- 바뀐 **컬럼 단위**로 이전값·이후값을 기록합니다.
CREATE TABLE financial_audit_logs (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transaction_id BIGINT UNSIGNED NULL     COMMENT '대상 입출금. 삭제 대비 FK 없이 값만',
  ref_table      VARCHAR(40) NOT NULL     COMMENT 'financial_transactions / payment_allocations',
  ref_id         BIGINT UNSIGNED NULL,
  action         VARCHAR(20) NOT NULL     COMMENT 'CREATE / UPDATE / CANCEL / REVERSE / ALLOCATE / UNALLOCATE',
  field_name     VARCHAR(40) NULL         COMMENT '바뀐 컬럼. UPDATE 는 컬럼마다 한 행',
  before_value   VARCHAR(500) NULL,
  after_value    VARCHAR(500) NULL,
  row_before     JSON NULL                COMMENT 'CANCEL·REVERSE 는 행 전체를 남깁니다',
  reason         VARCHAR(255) NULL,
  admin_id       BIGINT UNSIGNED NULL,
  admin_name     VARCHAR(50) NOT NULL,
  ip             VARCHAR(45) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_fal_txn (transaction_id, created_at),
  KEY ix_fal_action (action, created_at),
  KEY ix_fal_admin (admin_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='재무 변경 이력';


-- ============================================================================
--  6. 은행 거래내역 가져오기
--
--  인터넷뱅킹에서 받은 엑셀/CSV 를 그대로 올립니다. 올린 것은 **확정이 아닙니다.**
--  거래처 자동매칭은 추천일 뿐이고, 확실하지 않으면 미분류로 남겨 사람이 봅니다.
--  자동 확정은 틀렸을 때 되돌리는 비용이 훨씬 큽니다.
-- ============================================================================

CREATE TABLE bank_imports (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_entity_id BIGINT UNSIGNED NOT NULL,
  bank_account_id    BIGINT UNSIGNED NULL,
  file_name          VARCHAR(255) NOT NULL,
  file_hash          CHAR(64) NULL      COMMENT '같은 파일 두 번 올리는 것을 막습니다',
  row_count          INT UNSIGNED NOT NULL DEFAULT 0,
  matched_count      INT UNSIGNED NOT NULL DEFAULT 0,
  imported_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  imported_by        BIGINT UNSIGNED NULL,
  memo               VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bi_hash (business_entity_id, file_hash),
  KEY ix_bi_date (business_entity_id, imported_at),
  CONSTRAINT fk_bi_entity FOREIGN KEY (business_entity_id) REFERENCES business_entities(id),
  CONSTRAINT fk_bi_bank   FOREIGN KEY (bank_account_id) REFERENCES business_bank_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='은행 거래내역 업로드';

CREATE TABLE bank_import_rows (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  import_id          BIGINT UNSIGNED NOT NULL,
  business_entity_id BIGINT UNSIGNED NOT NULL,
  bank_account_id    BIGINT UNSIGNED NULL,
  line_no            INT UNSIGNED NOT NULL,
  txn_at             DATETIME NOT NULL                COMMENT '거래일시',
  in_amount          DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT '입금',
  out_amount         DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT '출금',
  balance            DECIMAL(15,2) NULL               COMMENT '거래 후 잔액',
  description        VARCHAR(255) NULL                COMMENT '거래내용',
  counterparty       VARCHAR(100) NULL                COMMENT '보낸분 / 받는분',
  memo               VARCHAR(255) NULL,
  raw_line           TEXT NULL                        COMMENT '원본 행 그대로 — 파싱이 틀려도 복구합니다',
  match_status       VARCHAR(20) NOT NULL DEFAULT 'UNMATCHED'
                     COMMENT 'UNMATCHED 미분류 / SUGGESTED 추천있음 / CONFIRMED 처리완료 / IGNORED 무시',
  suggested_company_id BIGINT UNSIGNED NULL           COMMENT '자동매칭 추천 — 확정이 아닙니다',
  match_score        TINYINT UNSIGNED NULL            COMMENT '0~100. 낮으면 사람이 봅니다',
  transaction_id     BIGINT UNSIGNED NULL             COMMENT '처리 결과',
  confirmed_at       DATETIME NULL,
  confirmed_by       BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bir_line (import_id, line_no),
  KEY ix_bir_status (business_entity_id, match_status, txn_at),
  KEY ix_bir_company (suggested_company_id),
  CONSTRAINT fk_bir_import  FOREIGN KEY (import_id) REFERENCES bank_imports(id) ON DELETE CASCADE,
  CONSTRAINT fk_bir_entity  FOREIGN KEY (business_entity_id) REFERENCES business_entities(id),
  CONSTRAINT fk_bir_company FOREIGN KEY (suggested_company_id) REFERENCES companies(id),
  CONSTRAINT fk_bir_txn     FOREIGN KEY (transaction_id) REFERENCES financial_transactions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='은행 거래내역 행';


-- ============================================================================
--  7. 미수금 (receivables) — 저장하지 않고 볼 때 계산합니다
--
--     미수금 = 매출금액 − 배분된 입금액
--     상태   = UNPAID 미입금 / PARTIAL 부분입금 / PAID 입금완료   (자동)
-- ============================================================================

CREATE OR REPLACE VIEW v_shipment_receivable AS
SELECT
    s.id                        AS shipment_id,
    s.business_entity_id,
    s.company_id,
    s.awb_no,
    s.voucher_date,
    s.status                    AS shipment_status,
    COALESCE(t.grand_total, 0)  AS sales_amount,
    COALESCE(a.paid, 0)         AS paid_amount,
    COALESCE(t.grand_total, 0) - COALESCE(a.paid, 0) AS balance,
    CASE
      WHEN s.status = 'CANCELLED'                           THEN 'CANCELLED'
      WHEN COALESCE(t.grand_total, 0) <= 0                  THEN 'NONE'
      WHEN COALESCE(a.paid, 0) <= 0                         THEN 'UNPAID'
      WHEN COALESCE(a.paid, 0) < COALESCE(t.grand_total, 0) THEN 'PARTIAL'
      ELSE 'PAID'
    END                         AS pay_status,
    DATEDIFF(CURDATE(), s.voucher_date) AS age_days,
    a.last_paid_at
FROM shipments s
LEFT JOIN v_shipment_totals t ON t.shipment_id = s.id
LEFT JOIN (
    SELECT pa.shipment_id,
           SUM(pa.amount) AS paid,
           MAX(f.txn_date) AS last_paid_at
    FROM payment_allocations pa
    JOIN financial_transactions f
      ON f.id = pa.transaction_id AND f.status = 'CONFIRMED' AND f.txn_type = 'IN'
    WHERE pa.shipment_id IS NOT NULL
    GROUP BY pa.shipment_id
) a ON a.shipment_id = s.id
WHERE s.deleted_at IS NULL;


-- 거래처 × 사업자 미수 합계 + 연령분석
CREATE OR REPLACE VIEW v_company_receivable AS
SELECT
    r.business_entity_id,
    r.company_id,
    COUNT(*)                                  AS shipment_cnt,
    SUM(r.sales_amount)                       AS sales_total,
    SUM(r.paid_amount)                        AS paid_total,
    SUM(r.balance)                            AS balance_total,
    SUM(CASE WHEN r.pay_status = 'UNPAID'  THEN 1 ELSE 0 END) AS unpaid_cnt,
    SUM(CASE WHEN r.pay_status = 'PARTIAL' THEN 1 ELSE 0 END) AS partial_cnt,
    SUM(CASE WHEN r.balance > 0 AND r.age_days <= 30 THEN r.balance ELSE 0 END) AS age_030,
    SUM(CASE WHEN r.balance > 0 AND r.age_days BETWEEN 31 AND 60 THEN r.balance ELSE 0 END) AS age_3160,
    SUM(CASE WHEN r.balance > 0 AND r.age_days BETWEEN 61 AND 90 THEN r.balance ELSE 0 END) AS age_6190,
    SUM(CASE WHEN r.balance > 0 AND r.age_days >  90 THEN r.balance ELSE 0 END) AS age_over90,
    MIN(CASE WHEN r.balance > 0 THEN r.voucher_date END) AS oldest_unpaid_date,
    MAX(r.last_paid_at)                       AS last_paid_at
FROM v_shipment_receivable r
WHERE r.pay_status <> 'CANCELLED'
GROUP BY r.business_entity_id, r.company_id;


-- 미지급금 — 매입 대비 지급. 받을 돈만 보이고 줄 돈이 안 보이면 자금계획이 안 섭니다
CREATE OR REPLACE VIEW v_purchase_payable AS
SELECT
    p.id                        AS purchase_id,
    p.business_entity_id,
    p.vendor_name,
    p.carrier_id,
    p.shipment_id,
    p.purchase_date,
    p.total_amount              AS purchase_amount,
    COALESCE(a.paid, 0)         AS paid_amount,
    p.total_amount - COALESCE(a.paid, 0) AS balance,
    CASE
      WHEN p.total_amount <= 0             THEN 'NONE'
      WHEN COALESCE(a.paid, 0) <= 0        THEN 'UNPAID'
      WHEN COALESCE(a.paid, 0) < p.total_amount THEN 'PARTIAL'
      ELSE 'PAID'
    END                         AS pay_status,
    DATEDIFF(CURDATE(), p.purchase_date) AS age_days
FROM purchases p
LEFT JOIN (
    SELECT pa.purchase_id, SUM(pa.amount) AS paid
    FROM payment_allocations pa
    JOIN financial_transactions f
      ON f.id = pa.transaction_id AND f.status = 'CONFIRMED' AND f.txn_type = 'OUT'
    WHERE pa.purchase_id IS NOT NULL
    GROUP BY pa.purchase_id
) a ON a.purchase_id = p.id
WHERE p.deleted_at IS NULL;


-- ============================================================================
--  8. 계좌 잔액 — 개시잔액 + 들어온 것 − 나간 것 (이체 포함)
--     이체는 잔액에는 반영되고 손익에는 안 잡힙니다
-- ============================================================================

CREATE OR REPLACE VIEW v_account_balance AS
SELECT
    b.id                AS bank_account_id,
    b.business_entity_id,
    b.bank_name,
    b.account_no,
    b.account_holder,
    b.account_type,
    b.purpose,
    b.is_active,
    b.opening_balance,
    COALESCE(i.total, 0) AS in_total,
    COALESCE(o.total, 0) AS out_total,
    b.opening_balance + COALESCE(i.total, 0) - COALESCE(o.total, 0) AS balance,
    GREATEST(COALESCE(i.last_date, '1900-01-01'),
             COALESCE(o.last_date, '1900-01-01')) AS last_txn_date
FROM business_bank_accounts b
LEFT JOIN (
    SELECT to_account_id AS acc, SUM(amount) AS total, MAX(txn_date) AS last_date
    FROM financial_transactions
    WHERE status = 'CONFIRMED' AND to_account_id IS NOT NULL
    GROUP BY to_account_id
) i ON i.acc = b.id
LEFT JOIN (
    SELECT from_account_id AS acc, SUM(amount + fee) AS total, MAX(txn_date) AS last_date
    FROM financial_transactions
    WHERE status = 'CONFIRMED' AND from_account_id IS NOT NULL
    GROUP BY from_account_id
) o ON o.acc = b.id;


-- ============================================================================
--  9. 거래처 원장 — 날짜순으로 [매출 / 입금 / 잔액]
--
--  차변(debit)  = 매출  — 받을 돈이 늘어남
--  대변(credit) = 입금  — 받을 돈이 줄어듦
--
--  잔액 누계는 여기서 계산하지 않습니다. 거래처 한 곳을 열었을 때 화면에서
--  위에서부터 더해 내려갑니다. 전월이월은 기간 시작일 이전 합계로 따로 구합니다.
--  전 거래처에 대해 누계를 돌리면 건수가 늘수록 급격히 느려집니다.
-- ============================================================================

CREATE OR REPLACE VIEW v_company_ledger AS
SELECT
    s.business_entity_id,
    s.company_id,
    s.voucher_date              AS txn_date,
    'SALES'                     AS txn_type,
    s.id                        AS ref_id,
    s.awb_no                    AS ref_no,
    CONCAT('매출 ', s.awb_no)   AS summary,
    COALESCE(t.grand_total, 0)  AS debit,
    0                           AS credit,
    s.created_at
FROM shipments s
LEFT JOIN v_shipment_totals t ON t.shipment_id = s.id
WHERE s.deleted_at IS NULL AND s.status <> 'CANCELLED'

UNION ALL
SELECT
    f.business_entity_id,
    f.company_id,
    f.txn_date,
    'PAYMENT',
    f.id,
    COALESCE(f.doc_no, CONCAT('R', f.id)),
    CONCAT('입금 ', COALESCE(NULLIF(f.summary, ''), f.counterparty, '')),
    0,
    f.amount,
    f.created_at
FROM financial_transactions f
WHERE f.status = 'CONFIRMED' AND f.txn_type = 'IN' AND f.company_id IS NOT NULL

-- 거래처에 돌려준 돈은 받을 돈이 다시 늘어나므로 차변입니다
UNION ALL
SELECT
    f.business_entity_id,
    f.company_id,
    f.txn_date,
    'REFUND',
    f.id,
    COALESCE(f.doc_no, CONCAT('D', f.id)),
    CONCAT('환불 ', COALESCE(f.summary, '')),
    f.amount,
    0,
    f.created_at
FROM financial_transactions f
WHERE f.status = 'CONFIRMED' AND f.txn_type = 'OUT'
  AND f.company_id IS NOT NULL AND f.payee_type = 'COMPANY';


-- ============================================================================
--  10. 권한 — 재무는 일반 CRUD 보다 강하게 나눕니다
--      조회 / 등록 / 수정 / 취소(역분개) 를 따로 줍니다. 특히 취소는 좁게.
-- ============================================================================

INSERT INTO permissions (code, group_ko, name_ko) VALUES
  ('CASH_VIEW', '입출금', '입출금 조회'),
  ('CASH_WRITE', '입출금', '입출금 등록'),
  ('CASH_EDIT', '입출금', '입출금 수정'),
  ('CASH_CANCEL', '입출금', '입출금 취소·역분개'),
  ('LEDGER_VIEW', '입출금', '거래처원장 조회'),
  ('ACCOUNT_MANAGE', '입출금', '계좌·비용분류 관리'),
  ('BANK_IMPORT', '입출금', '은행내역 가져오기'),
  ('PAYABLE_VIEW', '입출금', '미지급금 조회')
ON DUPLICATE KEY UPDATE name_ko = VALUES(name_ko);

-- 관리자·경영진·회계 : 전부
INSERT INTO role_permissions (role_code, permission_id)
SELECT r.role_code, p.id
FROM (SELECT 'SUPER_ADMIN' AS role_code UNION ALL
      SELECT 'MANAGER' UNION ALL
      SELECT 'ACCOUNTING') r
CROSS JOIN permissions p
WHERE p.group_ko = '입출금'
ON DUPLICATE KEY UPDATE role_code = VALUES(role_code);

-- 영업 : 조회만. 남의 거래처 입금을 손대지 못합니다
INSERT INTO role_permissions (role_code, permission_id)
SELECT 'SALES', p.id FROM permissions p
WHERE p.code IN ('CASH_VIEW', 'LEDGER_VIEW')
ON DUPLICATE KEY UPDATE role_code = VALUES(role_code);


-- ============================================================================
--  11. 기존 payments → financial_transactions 흡수
--      아직 0건일 것입니다. 그래도 돌려두면 나중에 안전합니다.
-- ============================================================================

INSERT INTO financial_transactions
  (business_entity_id, txn_type, txn_date, company_id, counterparty,
   to_account_id, method, supply_amount, tax_type, vat_amount, amount,
   summary, memo, status, created_at, created_by)
SELECT p.business_entity_id, 'IN', p.paid_at, p.company_id, p.depositor,
       p.bank_account_id, p.method, p.amount, 'ZERO', 0, p.amount,
       p.remark, NULL, 'CONFIRMED', p.created_at, p.created_by
FROM payments p
WHERE p.deleted_at IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM financial_transactions f
       WHERE f.txn_type = 'IN' AND f.txn_date = p.paid_at
         AND f.company_id = p.company_id AND f.amount = p.amount);

-- 청구서에 붙어 있던 것은 배분으로 옮깁니다
INSERT INTO payment_allocations (transaction_id, line_no, invoice_id, amount)
SELECT f.id, 1, p.invoice_id, p.amount
FROM payments p
JOIN financial_transactions f
  ON f.txn_type = 'IN' AND f.txn_date = p.paid_at
 AND f.company_id = p.company_id AND f.amount = p.amount
WHERE p.invoice_id IS NOT NULL AND p.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM payment_allocations a WHERE a.transaction_id = f.id);

UPDATE financial_transactions f
   SET f.alloc_amount = COALESCE(
       (SELECT SUM(a.amount) FROM payment_allocations a WHERE a.transaction_id = f.id), 0);

ALTER TABLE payments COMMENT = '[폐기] financial_transactions 로 이전됨. 새로 넣지 마세요';


-- ============================================================================
--  12. 확인
-- ============================================================================

SELECT COUNT(*) AS 비용분류수   FROM expense_categories;
SELECT COUNT(*) AS 입출금권한수 FROM permissions WHERE group_ko = '입출금';
SELECT COUNT(*) AS 입출금건수   FROM financial_transactions;
SELECT COUNT(*) AS 배분행수     FROM payment_allocations;

SELECT TABLE_NAME FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
   AND TABLE_NAME IN ('expense_categories','financial_transactions','payment_allocations',
                      'transaction_attachments','financial_audit_logs',
                      'bank_imports','bank_import_rows')
 ORDER BY TABLE_NAME;

SELECT TABLE_NAME FROM information_schema.VIEWS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME IN ('v_shipment_receivable','v_company_receivable','v_purchase_payable',
                      'v_account_balance','v_company_ledger')
 ORDER BY TABLE_NAME;


-- ============================================================
--  19. 기초잔액  (db/19_opening_balances.sql)
-- ============================================================

-- ============================================================================
--  기초잔액 — 옛 시스템에서 넘어온 미수·미지급을 "전환일 한 줄"로 받습니다
--
--  실행 순서 : 10 → 11 → 12 → 17 → 18 → **19(이 파일)**
--
--  왜 필요한가
--    옛 시스템 과거 입금(IS_PAYMENT)은 어느 전표에 붙었는지 기록이 없어
--    49,360건에 자동으로 맞출 수 없습니다. 그대로 이관하면
--      · 2015~2026년 전표 49,360건이 전부 "미입금" → 미수금 약 128.7억
--      · TSAMOUNT 매입 39,524건이 전부 "미지급"   → 미지급 약 68.2억
--    으로 뜹니다. 실제로는 몇 년 전에 다 받고 준 돈입니다.
--
--  어떻게 푸나 (기초잔액 방식 — 회계 시스템을 바꿀 때의 표준)
--    1) 전환일(ar_cutover_date)을 정합니다.
--    2) 전환일 **이전** 전표·매입은 미수/미지급 계산에서 **닫습니다.**
--       매출·매입 기록 자체는 그대로 남습니다 — 매출통계·손익은 변하지 않습니다.
--    3) 옛 시스템에서 전환일 기준 **거래처별 미수 잔액**을 받아 한 줄씩 넣습니다.
--    4) 전환일 이후 들어오는 옛 미수 입금은 이 기초잔액에 배분합니다.
--
--  하지 않은 것 : 옛 전표마다 "이관 정산" 가짜 입금을 붙이는 방식.
--    그러면 입출금 통계에 128억 입금이 찍히고 계좌잔액이 틀어집니다.
--    돈이 오가지 않았는데 오간 것처럼 기록하지 않습니다.
--
--  전환일을 비워 두면 아무것도 닫히지 않습니다 (지금까지와 같습니다).
--  데모 자료로 화면을 볼 때는 비워 두고, 실제 이관 때 넣으세요.
-- ============================================================================


-- ============================================================================
--  1. 전환일 설정
--     화면에서는 [기초잔액 관리] 에서만 바꿉니다. 바꾸면 전 거래처 미수가
--     한꺼번에 움직이므로 재무 이력에 남깁니다. 환경설정 화면에서는 읽기만 합니다.
-- ============================================================================

INSERT INTO app_settings
  (setting_key, setting_val, group_ko, label_ko, help_ko, input_type, options_csv, sort_order)
VALUES
  ('ar_cutover_date', NULL, '회계', '기초잔액 기준일 (전환일)',
   '이 날짜 이전 전표·매입은 미수/미지급에서 닫히고 기초잔액으로 대체됩니다. [기초잔액 관리]에서 바꿉니다.',
   'date', NULL, 10)
ON DUPLICATE KEY UPDATE label_ko = VALUES(label_ko), help_ko = VALUES(help_ko),
                        input_type = VALUES(input_type);


-- ============================================================================
--  2. 기초잔액
--
--  AR 미수  : 거래처(company_id) 단위. 거래처가 있어야 입금을 붙일 수 있습니다.
--  AP 미지급 : 업체명(counterparty_name) 단위. 운송사는 거래처가 아닐 때가 많습니다.
--
--  active_key 는 "살아 있는 행"끼리만 중복을 막는 장치입니다.
--  확정 상태면 party_key 가 들어가고, 취소하면 NULL 이 됩니다 — 취소한 뒤
--  같은 거래처를 다시 넣을 수 있어야 해서 이렇게 했습니다.
-- ============================================================================

CREATE TABLE opening_balances (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_entity_id BIGINT UNSIGNED NOT NULL,
  balance_type       VARCHAR(5)  NOT NULL      COMMENT 'AR 미수 / AP 미지급',
  company_id         BIGINT UNSIGNED NULL      COMMENT 'AR 은 필수',
  counterparty_name  VARCHAR(100) NULL         COMMENT 'AP 업체명 (운송사 등)',
  party_key          VARCHAR(120) NOT NULL     COMMENT 'C:<거래처id> / V:<업체명>',
  active_key         VARCHAR(120) NULL         COMMENT '확정이면 party_key, 취소면 NULL',
  as_of_date         DATE NOT NULL             COMMENT '기준일 = 전환일',
  origin_date        DATE NULL                 COMMENT '가장 오래된 미수 발생일 — 연령분석용',
  amount             DECIMAL(15,2) NOT NULL    COMMENT '전환일 기준 잔액',
  source             VARCHAR(20) NOT NULL DEFAULT 'MANUAL' COMMENT 'LEGACY 옛 시스템 / MANUAL 직접입력',
  legacy_ref         VARCHAR(150) NULL         COMMENT '옛 시스템 코드·이름 원본 그대로',
  memo               VARCHAR(255) NULL,
  status             VARCHAR(20) NOT NULL DEFAULT 'CONFIRMED' COMMENT 'CONFIRMED / CANCELLED',
  cancelled_at       DATETIME NULL,
  cancelled_by       BIGINT UNSIGNED NULL,
  cancel_reason      VARCHAR(255) NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by         BIGINT UNSIGNED NULL,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by         BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ob_active (business_entity_id, balance_type, active_key),
  KEY ix_ob_company (company_id),
  KEY ix_ob_type (business_entity_id, balance_type, status),
  CONSTRAINT fk_ob_entity  FOREIGN KEY (business_entity_id) REFERENCES business_entities(id),
  CONSTRAINT fk_ob_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='기초잔액 — 전환일 기준 미수·미지급';


-- 입금·출금을 기초잔액에 배분할 수 있게 합니다
ALTER TABLE payment_allocations
  ADD COLUMN opening_balance_id BIGINT UNSIGNED NULL COMMENT '기초잔액에 충당' AFTER purchase_id,
  ADD KEY ix_pa_opening (opening_balance_id),
  ADD CONSTRAINT fk_pa_opening FOREIGN KEY (opening_balance_id) REFERENCES opening_balances(id);


-- ============================================================================
--  3. 기초잔액별 남은 금액
-- ============================================================================

CREATE OR REPLACE VIEW v_opening_balance AS
SELECT
    ob.id, ob.business_entity_id, ob.balance_type, ob.company_id, ob.counterparty_name,
    ob.party_key, ob.as_of_date, ob.origin_date, ob.amount, ob.source, ob.legacy_ref,
    ob.memo, ob.status, ob.created_at,
    COALESCE(a.alloc, 0) AS allocated,
    CASE WHEN ob.status = 'CANCELLED' THEN 0
         ELSE ob.amount - COALESCE(a.alloc, 0) END AS remaining,
    CASE
      WHEN ob.status = 'CANCELLED'         THEN 'CANCELLED'
      WHEN ob.amount <= 0                  THEN 'NONE'
      WHEN COALESCE(a.alloc, 0) <= 0       THEN 'UNPAID'
      WHEN COALESCE(a.alloc, 0) < ob.amount THEN 'PARTIAL'
      ELSE 'PAID'
    END AS pay_status,
    DATEDIFF(CURDATE(), COALESCE(ob.origin_date, ob.as_of_date)) AS age_days,
    a.last_paid_at
FROM opening_balances ob
LEFT JOIN (
    SELECT pa.opening_balance_id,
           SUM(pa.amount)  AS alloc,
           MAX(f.txn_date) AS last_paid_at
    FROM payment_allocations pa
    JOIN financial_transactions f ON f.id = pa.transaction_id AND f.status = 'CONFIRMED'
    WHERE pa.opening_balance_id IS NOT NULL
    GROUP BY pa.opening_balance_id
) a ON a.opening_balance_id = ob.id;


-- ============================================================================
--  4. 전표별 미수 — 전환일 이전은 닫습니다  (18번 정의를 대체)
--
--  전환일 값은 app_settings 에서 읽습니다. 비어 있거나 형식이 틀리면
--  1000-01-01 로 보아 아무것도 닫지 않습니다 (안전한 쪽으로 실패).
--
--  취소된 전표도 잔액을 0 으로 만들었습니다. 전에는 상태만 CANCELLED 였고
--  잔액은 남아 있어서, 상태를 안 거르는 화면(장기미수 목록)에 잡혔습니다.
-- ============================================================================

CREATE OR REPLACE VIEW v_shipment_receivable AS
SELECT
    s.id                        AS shipment_id,
    s.business_entity_id,
    s.company_id,
    s.awb_no,
    s.voucher_date,
    s.status                    AS shipment_status,
    COALESCE(t.grand_total, 0)  AS sales_amount,
    COALESCE(a.paid, 0)         AS paid_amount,
    CASE
      WHEN s.status = 'CANCELLED'     THEN 0
      WHEN s.voucher_date < c.cutover THEN 0
      ELSE COALESCE(t.grand_total, 0) - COALESCE(a.paid, 0)
    END                         AS balance,
    CASE
      WHEN s.status = 'CANCELLED'                           THEN 'CANCELLED'
      WHEN s.voucher_date < c.cutover                       THEN 'OPENING'
      WHEN COALESCE(t.grand_total, 0) <= 0                  THEN 'NONE'
      WHEN COALESCE(a.paid, 0) <= 0                         THEN 'UNPAID'
      WHEN COALESCE(a.paid, 0) < COALESCE(t.grand_total, 0) THEN 'PARTIAL'
      ELSE 'PAID'
    END                         AS pay_status,
    DATEDIFF(CURDATE(), s.voucher_date) AS age_days,
    a.last_paid_at
FROM shipments s
CROSS JOIN (
    SELECT COALESCE(
        (SELECT STR_TO_DATE(NULLIF(TRIM(setting_val), ''), '%Y-%m-%d')
           FROM app_settings WHERE setting_key = 'ar_cutover_date'),
        DATE('1000-01-01')) AS cutover
) c
LEFT JOIN v_shipment_totals t ON t.shipment_id = s.id
LEFT JOIN (
    SELECT pa.shipment_id,
           SUM(pa.amount)  AS paid,
           MAX(f.txn_date) AS last_paid_at
    FROM payment_allocations pa
    JOIN financial_transactions f
      ON f.id = pa.transaction_id AND f.status = 'CONFIRMED' AND f.txn_type = 'IN'
    WHERE pa.shipment_id IS NOT NULL
    GROUP BY pa.shipment_id
) a ON a.shipment_id = s.id
WHERE s.deleted_at IS NULL;


-- ============================================================================
--  5. 거래처별 미수 = 전환일 이후 전표 미수 + 기초 미수 잔액  (18번 정의를 대체)
-- ============================================================================

CREATE OR REPLACE VIEW v_company_receivable AS
SELECT
    x.business_entity_id,
    x.company_id,
    SUM(x.shipment_cnt)   AS shipment_cnt,
    SUM(x.sales_total)    AS sales_total,
    SUM(x.paid_total)     AS paid_total,
    SUM(x.balance_total)  AS balance_total,
    SUM(x.opening_total)  AS opening_total,
    SUM(x.unpaid_cnt)     AS unpaid_cnt,
    SUM(x.partial_cnt)    AS partial_cnt,
    SUM(x.age_030)        AS age_030,
    SUM(x.age_3160)       AS age_3160,
    SUM(x.age_6190)       AS age_6190,
    SUM(x.age_over90)     AS age_over90,
    MIN(x.oldest_unpaid_date) AS oldest_unpaid_date,
    MAX(x.last_paid_at)   AS last_paid_at
FROM (
    SELECT
        r.business_entity_id, r.company_id,
        COUNT(*)                AS shipment_cnt,
        SUM(r.sales_amount)     AS sales_total,
        SUM(r.paid_amount)      AS paid_total,
        SUM(r.balance)          AS balance_total,
        0                       AS opening_total,
        SUM(CASE WHEN r.pay_status = 'UNPAID'  THEN 1 ELSE 0 END) AS unpaid_cnt,
        SUM(CASE WHEN r.pay_status = 'PARTIAL' THEN 1 ELSE 0 END) AS partial_cnt,
        SUM(CASE WHEN r.balance > 0 AND r.age_days <= 30 THEN r.balance ELSE 0 END) AS age_030,
        SUM(CASE WHEN r.balance > 0 AND r.age_days BETWEEN 31 AND 60 THEN r.balance ELSE 0 END) AS age_3160,
        SUM(CASE WHEN r.balance > 0 AND r.age_days BETWEEN 61 AND 90 THEN r.balance ELSE 0 END) AS age_6190,
        SUM(CASE WHEN r.balance > 0 AND r.age_days > 90 THEN r.balance ELSE 0 END) AS age_over90,
        MIN(CASE WHEN r.balance > 0 THEN r.voucher_date END) AS oldest_unpaid_date,
        MAX(r.last_paid_at)     AS last_paid_at
    FROM v_shipment_receivable r
    WHERE r.pay_status NOT IN ('CANCELLED', 'OPENING')
    GROUP BY r.business_entity_id, r.company_id

    UNION ALL
    SELECT
        o.business_entity_id, o.company_id,
        0, o.amount, o.allocated, o.remaining, o.remaining,
        CASE WHEN o.pay_status = 'UNPAID'  THEN 1 ELSE 0 END,
        CASE WHEN o.pay_status = 'PARTIAL' THEN 1 ELSE 0 END,
        CASE WHEN o.remaining > 0 AND o.age_days <= 30 THEN o.remaining ELSE 0 END,
        CASE WHEN o.remaining > 0 AND o.age_days BETWEEN 31 AND 60 THEN o.remaining ELSE 0 END,
        CASE WHEN o.remaining > 0 AND o.age_days BETWEEN 61 AND 90 THEN o.remaining ELSE 0 END,
        CASE WHEN o.remaining > 0 AND o.age_days > 90 THEN o.remaining ELSE 0 END,
        CASE WHEN o.remaining > 0 THEN COALESCE(o.origin_date, o.as_of_date) END,
        o.last_paid_at
    FROM v_opening_balance o
    WHERE o.balance_type = 'AR' AND o.status = 'CONFIRMED' AND o.company_id IS NOT NULL
) x
GROUP BY x.business_entity_id, x.company_id;


-- ============================================================================
--  6. 매입별 미지급 — 전환일 이전은 닫습니다  (18번 정의를 대체)
--     전환일 이전 미지급은 기초잔액(AP)으로 따로 들고 갑니다.
-- ============================================================================

CREATE OR REPLACE VIEW v_purchase_payable AS
SELECT
    p.id                        AS purchase_id,
    p.business_entity_id,
    p.vendor_name,
    p.carrier_id,
    p.shipment_id,
    p.purchase_date,
    p.total_amount              AS purchase_amount,
    COALESCE(a.paid, 0)         AS paid_amount,
    CASE WHEN p.purchase_date < c.cutover THEN 0
         ELSE p.total_amount - COALESCE(a.paid, 0) END AS balance,
    CASE
      WHEN p.purchase_date < c.cutover            THEN 'OPENING'
      WHEN p.total_amount <= 0                    THEN 'NONE'
      WHEN COALESCE(a.paid, 0) <= 0               THEN 'UNPAID'
      WHEN COALESCE(a.paid, 0) < p.total_amount   THEN 'PARTIAL'
      ELSE 'PAID'
    END                         AS pay_status,
    DATEDIFF(CURDATE(), p.purchase_date) AS age_days
FROM purchases p
CROSS JOIN (
    SELECT COALESCE(
        (SELECT STR_TO_DATE(NULLIF(TRIM(setting_val), ''), '%Y-%m-%d')
           FROM app_settings WHERE setting_key = 'ar_cutover_date'),
        DATE('1000-01-01')) AS cutover
) c
LEFT JOIN (
    SELECT pa.purchase_id, SUM(pa.amount) AS paid
    FROM payment_allocations pa
    JOIN financial_transactions f
      ON f.id = pa.transaction_id AND f.status = 'CONFIRMED' AND f.txn_type = 'OUT'
    WHERE pa.purchase_id IS NOT NULL
    GROUP BY pa.purchase_id
) a ON a.purchase_id = p.id
WHERE p.deleted_at IS NULL;


-- ============================================================================
--  7. 거래처 원장 — 전환일 이전 매출 대신 기초잔액 한 줄  (18번 정의를 대체)
--
--  전환일 이전 매출을 원장에 그대로 두면, 그 매출에 대응하는 옛 입금이 없어서
--  128억이 받을 돈으로 쌓입니다. 기초잔액이 그 자리를 대신합니다.
--  매출 기록 자체는 매출전표·매출통계에 그대로 있습니다.
-- ============================================================================

CREATE OR REPLACE VIEW v_company_ledger AS
SELECT
    s.business_entity_id,
    s.company_id,
    s.voucher_date              AS txn_date,
    'SALES'                     AS txn_type,
    s.id                        AS ref_id,
    s.awb_no                    AS ref_no,
    CONCAT('매출 ', s.awb_no)   AS summary,
    COALESCE(t.grand_total, 0)  AS debit,
    0                           AS credit,
    s.created_at
FROM shipments s
CROSS JOIN (
    SELECT COALESCE(
        (SELECT STR_TO_DATE(NULLIF(TRIM(setting_val), ''), '%Y-%m-%d')
           FROM app_settings WHERE setting_key = 'ar_cutover_date'),
        DATE('1000-01-01')) AS cutover
) c
LEFT JOIN v_shipment_totals t ON t.shipment_id = s.id
WHERE s.deleted_at IS NULL AND s.status <> 'CANCELLED'
  AND s.voucher_date >= c.cutover

UNION ALL
SELECT
    ob.business_entity_id,
    ob.company_id,
    ob.as_of_date,
    'OPENING',
    ob.id,
    CONCAT('OB', ob.id),
    '기초잔액 (전환일 이전 미수)',
    ob.amount,
    0,
    ob.created_at
FROM opening_balances ob
WHERE ob.status = 'CONFIRMED' AND ob.balance_type = 'AR' AND ob.company_id IS NOT NULL

UNION ALL
SELECT
    f.business_entity_id,
    f.company_id,
    f.txn_date,
    'PAYMENT',
    f.id,
    COALESCE(f.doc_no, CONCAT('R', f.id)),
    CONCAT('입금 ', COALESCE(NULLIF(f.summary, ''), f.counterparty, '')),
    0,
    f.amount,
    f.created_at
FROM financial_transactions f
WHERE f.status = 'CONFIRMED' AND f.txn_type = 'IN' AND f.company_id IS NOT NULL

UNION ALL
SELECT
    f.business_entity_id,
    f.company_id,
    f.txn_date,
    'REFUND',
    f.id,
    COALESCE(f.doc_no, CONCAT('D', f.id)),
    CONCAT('환불 ', COALESCE(f.summary, '')),
    f.amount,
    0,
    f.created_at
FROM financial_transactions f
WHERE f.status = 'CONFIRMED' AND f.txn_type = 'OUT'
  AND f.company_id IS NOT NULL AND f.payee_type = 'COMPANY';


-- ============================================================================
--  8. 권한
-- ============================================================================

INSERT INTO permissions (code, group_ko, name_ko) VALUES
  ('OPENING_MANAGE', '입출금', '기초잔액 관리')
ON DUPLICATE KEY UPDATE name_ko = VALUES(name_ko);

INSERT INTO role_permissions (role_code, permission_id)
SELECT r.role_code, p.id
FROM (SELECT 'SUPER_ADMIN' AS role_code UNION ALL
      SELECT 'MANAGER' UNION ALL
      SELECT 'ACCOUNTING') r
CROSS JOIN permissions p
WHERE p.code = 'OPENING_MANAGE'
ON DUPLICATE KEY UPDATE role_code = VALUES(role_code);


-- ============================================================================
--  9. 확인
-- ============================================================================

SELECT setting_key, setting_val FROM app_settings WHERE setting_key = 'ar_cutover_date';
SELECT COUNT(*) AS 기초잔액행수 FROM opening_balances;
SELECT TABLE_NAME FROM information_schema.VIEWS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME IN ('v_opening_balance','v_shipment_receivable','v_company_receivable',
                      'v_purchase_payable','v_company_ledger')
 ORDER BY TABLE_NAME;
