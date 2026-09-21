-- ============================================================================
--  입금확인서 (2026-09-22)
--
--  운영 DB 에는 app/bootstrap.php 의 schema_upgrade_receipts() 가 입금확인서 화면을 열 때 자동으로 만듭니다.
--  (이미 있으면 건너뜀) 이 파일은 새로 설치할 때와 기록용입니다.
--
--  · 거래처 · 기간을 골라 확정된 입금(financial_transactions IN/CONFIRMED)을 묶어 발행합니다. 금액은 발행 시점에 고정.
--  · 수록 입금이 취소되거나 금액이 바뀌면 "재발행 필요" 가 표시되고, [재발행] 하면 현재 값으로 다시 계산해
--    차수(revision)가 올라 출력물에 REV. 로 표시됩니다. 발행 · 재발행 · 취소는 activity_logs 에 이전값 · 이후값 · 사유로 남습니다.
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS receipts (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_entity_id BIGINT UNSIGNED NOT NULL,
  receipt_no         VARCHAR(40) NOT NULL              COMMENT 'GPA-RC-202609-001',
  company_id         BIGINT UNSIGNED NOT NULL,
  receipt_date       DATE NOT NULL                     COMMENT '확인서 일자',
  period_from        DATE NULL,
  period_to          DATE NULL,
  amount_total       DECIMAL(15,2) NOT NULL DEFAULT 0  COMMENT '입금 합계 (발행 시점 고정)',
  txn_count          INT UNSIGNED NOT NULL DEFAULT 0,
  status             VARCHAR(20) NOT NULL DEFAULT 'ISSUED' COMMENT 'ISSUED/CANCELLED',
  issued_at          DATETIME NULL                     COMMENT '마지막 발행(재발행) 시각',
  issue_count        INT UNSIGNED NOT NULL DEFAULT 1   COMMENT '발행 횟수',
  revision           INT UNSIGNED NOT NULL DEFAULT 1   COMMENT '발행본 차수 (재발행하면 +1)',
  reissue_reason     VARCHAR(255) NULL                 COMMENT '마지막 재발행 사유',
  remark             VARCHAR(255) NULL                 COMMENT '확인서에 찍는 비고',
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by         BIGINT UNSIGNED NULL,
  deleted_at         DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rc_no (business_entity_id, receipt_no),
  KEY ix_rc_company (company_id, receipt_date),
  CONSTRAINT fk_rc_entity  FOREIGN KEY (business_entity_id) REFERENCES business_entities(id),
  CONSTRAINT fk_rc_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='입금확인서';

CREATE TABLE IF NOT EXISTS receipt_transactions (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  receipt_id     BIGINT UNSIGNED NOT NULL,
  transaction_id BIGINT UNSIGNED NOT NULL              COMMENT 'financial_transactions.id (입금)',
  line_no        SMALLINT NOT NULL,
  amount         DECIMAL(15,2) NOT NULL DEFAULT 0      COMMENT '발행 시점 금액',
  PRIMARY KEY (id),
  UNIQUE KEY uq_rt (receipt_id, transaction_id),
  KEY ix_rt_txn (transaction_id),
  CONSTRAINT fk_rt_receipt FOREIGN KEY (receipt_id) REFERENCES receipts(id) ON DELETE CASCADE,
  CONSTRAINT fk_rt_txn FOREIGN KEY (transaction_id) REFERENCES financial_transactions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='입금확인서 수록 입금';
