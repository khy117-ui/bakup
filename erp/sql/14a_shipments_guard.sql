-- ============================================================================
--  매출전표 — [A] 보존장치 만들기
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
--  전제조건 — 12_seed.sql · 13a_companies_guard.sql 이 먼저 실행됐는지
--  아래가 기대와 다르면 **여기서 멈추세요.**
--  (GPA 가 없으면 6번 INSERT 가 NOT NULL 위반으로 에러를 냅니다 — 조용히 넘어가지 않습니다)
-- ============================================================================

SELECT (SELECT COUNT(*) FROM business_entities WHERE code = 'GPA')           AS GPA사업자_1이어야함,
       (SELECT COUNT(*) FROM carriers WHERE code IN ('DHL','FEDEX','UPS','EMS')) AS 운송사_4여야함,
       (SELECT COUNT(*) FROM document_types WHERE code = 'AWB')              AS AWB문서종류_1이어야함,
       (SELECT COUNT(*) FROM companies)                                      AS 거래처_이관됐어야함,
       (SELECT COUNT(*) FROM shipments)                                      AS 기존전표_0이어야함;

-- ============================================================================
--  1. 이관 표시용 컬럼 추가
--  값이 이상한 전표를 버리지 않고 '표시해서' 넣기 위한 자리입니다.
-- ============================================================================

ALTER TABLE shipments
  ADD COLUMN migration_flag VARCHAR(60) NULL COMMENT '이관 시 보정한 내용. NULL = 정상'
      AFTER legacy_idx,
  ADD KEY ix_ship_migflag (migration_flag);


-- ============================================================================
--  2. 원본 보관 — shipments_staging (IS_SALES 51컬럼 그대로)
--  타입 변환 없음. 전부 VARCHAR / TEXT. 적재 후 읽기 전용입니다.
-- ============================================================================

CREATE TABLE shipments_staging (
  staging_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  IDX          VARCHAR(20)  NULL,
  COMPANY      VARCHAR(50)  NULL              COMMENT '거래처/SHIPPER 상호',
  EADDRESS     VARCHAR(500) NULL,
  PHONE        VARCHAR(20)  NULL,
  FAX          VARCHAR(20)  NULL,
  BUYERCODE    VARCHAR(30)  NULL              COMMENT 'CONSIGNEE 상호/코드',
  CONTACTNAME  VARCHAR(50)  NULL,
  BEADDRESS    VARCHAR(500) NULL,
  BPHONE       VARCHAR(20)  NULL,
  BFAX         VARCHAR(20)  NULL,
  ARRIVAL_N    VARCHAR(50)  NULL              COMMENT '도착지',
  FACTORY_A    VARCHAR(50)  NULL,
  TRANSIT_A    VARCHAR(50)  NULL,
  WEIGHT       VARCHAR(100) NULL,
  AMOUNT       VARCHAR(100) NULL              COMMENT '매출금액 (콤마 포함)',
  FUEL         VARCHAR(100) NULL              COMMENT '유류할증',
  DANGA_NUM    VARCHAR(20)  NULL              COMMENT '단가표 번호',
  DANGA_NAME   VARCHAR(100) NULL              COMMENT '단가표/서비스 이름 — 운송사 추론에 사용',
  PRICE        VARCHAR(100) NULL              COMMENT '기본가격',
  DCYUL        VARCHAR(100) NULL              COMMENT '할인율',
  COLMONEY     VARCHAR(100) NULL              COMMENT '의미 미확정 (착불금액 추정)',
  TSNUM        VARCHAR(100) NULL,
  TSWEIGHT     VARCHAR(30)  NULL,
  TSAMOUNT     VARCHAR(100) NULL              COMMENT '추가운임',
  DESCRIPTION  VARCHAR(50)  NULL,
  PCS          VARCHAR(50)  NULL,
  UNIT         VARCHAR(50)  NULL,
  MAMOUNT      VARCHAR(50)  NULL              COMMENT '매입금액 추정',
  DATESHIP     VARCHAR(20)  NULL,
  CONTENTS     VARCHAR(500) NULL,
  SPOT         VARCHAR(30)  NULL,
  COMM         VARCHAR(30)  NULL,
  REGDATE      VARCHAR(30)  NULL,
  BLNUM        VARCHAR(50)  NULL              COMMENT 'AWB 번호',
  `INOUT`        VARCHAR(10)  NULL              COMMENT '수출/수입',
  COLLECT      VARCHAR(10)  NULL,
  SALEDATE     VARCHAR(20)  NULL              COMMENT '전표일',
  DIVISION     VARCHAR(10)  NULL,
  BUSINESS     VARCHAR(20)  NULL,
  TEAM         VARCHAR(10)  NULL,
  LICENCE      VARCHAR(10)  NULL              COMMENT '영세율 여부',
  BILL         VARCHAR(10)  NULL              COMMENT '청구 여부',
  TAXES        VARCHAR(10)  NULL              COMMENT '과세 여부',
  DEPOSIT      VARCHAR(10)  NULL              COMMENT '입금 여부',
  file1        VARCHAR(500) NULL,
  filenum      VARCHAR(50)  NULL,
  remark       VARCHAR(500) NULL,
  GUBUN        VARCHAR(5)   NULL,
  TSWEIGHT2    VARCHAR(30)  NULL,
  UPTDATE      VARCHAR(30)  NULL,
  UPTUSER      VARCHAR(20)  NULL,

  raw_json     JSON NOT NULL                  COMMENT '원본 행 전체 — 컬럼 목록이 틀려도 값이 남는다',
  raw_sha256   CHAR(64) NULL,
  loaded_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  source_file  VARCHAR(255) NULL,

  PRIMARY KEY (staging_id),
  KEY ix_sstg_idx (IDX),
  KEY ix_sstg_blnum (BLNUM),
  KEY ix_sstg_company (COMPANY),
  KEY ix_sstg_saledate (SALEDATE)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='IS_SALES 원본 무손실 보관 — 적재 후 읽기 전용';


-- ============================================================================
--  3. 미해석 컬럼 보관
-- ============================================================================

CREATE TABLE shipments_legacy_extra (
  shipment_id BIGINT UNSIGNED NOT NULL,
  colmoney    VARCHAR(100) NULL   COMMENT '의미 미확정 — 착불금액 추정',
  tsnum       VARCHAR(100) NULL,
  tsweight    VARCHAR(30)  NULL,
  tsweight2   VARCHAR(30)  NULL,
  division    VARCHAR(10)  NULL   COMMENT '의미 미확정',
  gubun       VARCHAR(5)   NULL   COMMENT '의미 미확정',
  collect_yn  VARCHAR(10)  NULL   COMMENT '착불 여부 추정',
  business    VARCHAR(20)  NULL   COMMENT 'CUSTOMERS.BUSINESS 와 같은 축',
  factory_a   VARCHAR(50)  NULL,
  transit_a   VARCHAR(50)  NULL,
  licence_yn  VARCHAR(10)  NULL   COMMENT 'LICENCE 원본',
  taxes_yn    VARCHAR(10)  NULL   COMMENT 'TAXES 원본',
  bill_yn     VARCHAR(10)  NULL   COMMENT 'BILL 원본',
  deposit_yn  VARCHAR(10)  NULL   COMMENT 'DEPOSIT 원본',
  uptuser     VARCHAR(20)  NULL,
  PRIMARY KEY (shipment_id),
  CONSTRAINT fk_sle_ship FOREIGN KEY (shipment_id) REFERENCES shipments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='IS_SALES 미해석 컬럼 보관';


-- ============================================================================
--  4. 변경 이력 — shipments_history
--  전표와 비용항목의 UPDATE / DELETE 직전 행을 남깁니다. 금액이라 더 엄격하게 봅니다.
-- ============================================================================

CREATE TABLE shipments_history (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shipment_id BIGINT UNSIGNED NOT NULL,
  action      VARCHAR(20) NOT NULL
              COMMENT 'UPDATE / DELETE / CHARGE_UPDATE / CHARGE_DELETE',
  row_before  JSON NOT NULL,
  changed_by  VARCHAR(100) NULL,
  app_user    VARCHAR(100) NULL,
  changed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_sh_ship (shipment_id, changed_at),
  KEY ix_sh_action (action, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='매출전표 변경 이력 — 복구용';

DELIMITER $$

CREATE TRIGGER trg_shipments_before_update
BEFORE UPDATE ON shipments FOR EACH ROW
BEGIN
  INSERT INTO shipments_history (shipment_id, action, row_before, changed_by, app_user)
  VALUES (OLD.id, 'UPDATE', JSON_OBJECT(
      'id', OLD.id, 'business_entity_id', OLD.business_entity_id, 'company_id', OLD.company_id,
      'contact_id', OLD.contact_id, 'awb_no', OLD.awb_no, 'awb_source', OLD.awb_source,
      'mawb_no', OLD.mawb_no, 'barcode_path', OLD.barcode_path,
      'voucher_date', OLD.voucher_date, 'ship_date', OLD.ship_date,
      'trade_type', OLD.trade_type, 'carrier_id', OLD.carrier_id, 'service_id', OLD.service_id,
      'origin_country', OLD.origin_country, 'origin_city', OLD.origin_city,
      'dest_country', OLD.dest_country, 'dest_city', OLD.dest_city, 'zone_no', OLD.zone_no,
      'actual_weight', OLD.actual_weight, 'volume_weight', OLD.volume_weight,
      'charge_weight', OLD.charge_weight, 'package_count', OLD.package_count,
      'snap_base_price', OLD.snap_base_price, 'snap_discount_rate', OLD.snap_discount_rate,
      'snap_discount_amt', OLD.snap_discount_amt, 'snap_discounted', OLD.snap_discounted,
      'snap_fuel_rate', OLD.snap_fuel_rate, 'snap_fuel_amt', OLD.snap_fuel_amt,
      'snap_supply_price', OLD.snap_supply_price, 'snap_rate_table_id', OLD.snap_rate_table_id,
      'snap_taken_at', OLD.snap_taken_at, 'status', OLD.status,
      'approval_status', OLD.approval_status, 'sales_team', OLD.sales_team,
      'sales_rep', OLD.sales_rep, 'remark', OLD.remark, 'created_at', OLD.created_at,
      'created_by', OLD.created_by, 'updated_at', OLD.updated_at, 'updated_by', OLD.updated_by,
      'deleted_at', OLD.deleted_at, 'legacy_idx', OLD.legacy_idx
    ),
          CURRENT_USER(), @app_user);
END$$

CREATE TRIGGER trg_shipments_before_delete
BEFORE DELETE ON shipments FOR EACH ROW
BEGIN
  INSERT INTO shipments_history (shipment_id, action, row_before, changed_by, app_user)
  VALUES (OLD.id, 'DELETE', JSON_OBJECT(
      'id', OLD.id, 'business_entity_id', OLD.business_entity_id, 'company_id', OLD.company_id,
      'contact_id', OLD.contact_id, 'awb_no', OLD.awb_no, 'awb_source', OLD.awb_source,
      'mawb_no', OLD.mawb_no, 'barcode_path', OLD.barcode_path,
      'voucher_date', OLD.voucher_date, 'ship_date', OLD.ship_date,
      'trade_type', OLD.trade_type, 'carrier_id', OLD.carrier_id, 'service_id', OLD.service_id,
      'origin_country', OLD.origin_country, 'origin_city', OLD.origin_city,
      'dest_country', OLD.dest_country, 'dest_city', OLD.dest_city, 'zone_no', OLD.zone_no,
      'actual_weight', OLD.actual_weight, 'volume_weight', OLD.volume_weight,
      'charge_weight', OLD.charge_weight, 'package_count', OLD.package_count,
      'snap_base_price', OLD.snap_base_price, 'snap_discount_rate', OLD.snap_discount_rate,
      'snap_discount_amt', OLD.snap_discount_amt, 'snap_discounted', OLD.snap_discounted,
      'snap_fuel_rate', OLD.snap_fuel_rate, 'snap_fuel_amt', OLD.snap_fuel_amt,
      'snap_supply_price', OLD.snap_supply_price, 'snap_rate_table_id', OLD.snap_rate_table_id,
      'snap_taken_at', OLD.snap_taken_at, 'status', OLD.status,
      'approval_status', OLD.approval_status, 'sales_team', OLD.sales_team,
      'sales_rep', OLD.sales_rep, 'remark', OLD.remark, 'created_at', OLD.created_at,
      'created_by', OLD.created_by, 'updated_at', OLD.updated_at, 'updated_by', OLD.updated_by,
      'deleted_at', OLD.deleted_at, 'legacy_idx', OLD.legacy_idx
    ),
          CURRENT_USER(), @app_user);
END$$

-- 비용항목도 금액이므로 같이 남깁니다
CREATE TRIGGER trg_charges_before_update
BEFORE UPDATE ON shipment_charges FOR EACH ROW
BEGIN
  INSERT INTO shipments_history (shipment_id, action, row_before, changed_by, app_user)
  VALUES (OLD.shipment_id, 'CHARGE_UPDATE', JSON_OBJECT(
      'id', OLD.id, 'shipment_id', OLD.shipment_id, 'line_no', OLD.line_no,
      'charge_type', OLD.charge_type, 'item_name', OLD.item_name, 'qty', OLD.qty,
      'unit_price', OLD.unit_price, 'supply_amount', OLD.supply_amount,
      'tax_type', OLD.tax_type, 'tax_rate', OLD.tax_rate, 'tax_amount', OLD.tax_amount,
      'total_amount', OLD.total_amount, 'remark', OLD.remark
    ),
          CURRENT_USER(), @app_user);
END$$

CREATE TRIGGER trg_charges_before_delete
BEFORE DELETE ON shipment_charges FOR EACH ROW
BEGIN
  INSERT INTO shipments_history (shipment_id, action, row_before, changed_by, app_user)
  VALUES (OLD.shipment_id, 'CHARGE_DELETE', JSON_OBJECT(
      'id', OLD.id, 'shipment_id', OLD.shipment_id, 'line_no', OLD.line_no,
      'charge_type', OLD.charge_type, 'item_name', OLD.item_name, 'qty', OLD.qty,
      'unit_price', OLD.unit_price, 'supply_amount', OLD.supply_amount,
      'tax_type', OLD.tax_type, 'tax_rate', OLD.tax_rate, 'tax_amount', OLD.tax_amount,
      'total_amount', OLD.total_amount, 'remark', OLD.remark
    ),
          CURRENT_USER(), @app_user);
END$$

DELIMITER ;

