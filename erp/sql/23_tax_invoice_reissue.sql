-- ============================================================================
--  세금계산서 재발행 · 수정세금계산서  (2026-09-22)
--
--  운영 DB 에는 app/bootstrap.php 의 schema_upgrade_tax_invoices() 가 세금계산서 화면을 열 때 자동으로 붙입니다.
--  (이미 있으면 건너뜀) 이 파일은 새로 설치할 때와 기록용입니다.
--
--  · 국세청 전송 전(승인번호 없음) 문서는 [재발행] 으로 청구서의 현재 금액으로 다시 만들고 이전 문서는 취소(대체) 처리.
--  · 국세청 전송 후(승인번호 있음) 문서는 [수정세금계산서 발행] — 원본은 그대로 두고 original_id · modify_reason 으로
--    연결된 새 문서를 만듭니다 (착오정정: (-)원본 + (+)정정본 / 공급가액 변동: 차액 한 장).
--  · 모든 발행 · 재발행 · 취소는 activity_logs 에 이전값 · 이후값 · 사유와 함께 남습니다.
-- ============================================================================

SET NAMES utf8mb4;

ALTER TABLE tax_invoices ADD COLUMN issued_at DATETIME NULL
  COMMENT '발행 처리 시각' AFTER status;
ALTER TABLE tax_invoices ADD COLUMN revision INT UNSIGNED NOT NULL DEFAULT 1
  COMMENT '같은 청구서의 몇 번째 발행본인지 (1 = 처음)' AFTER issued_at;
ALTER TABLE tax_invoices ADD COLUMN reissue_reason VARCHAR(255) NULL
  COMMENT '재발행 · 수정발행 사유(자유 입력)' AFTER revision;
ALTER TABLE tax_invoices ADD COLUMN replaced_by BIGINT UNSIGNED NULL
  COMMENT '이 문서를 대체(재발행 · 수정)한 새 문서 id' AFTER reissue_reason;

UPDATE tax_invoices SET issued_at = COALESCE(sent_at, created_at) WHERE status IN ('ISSUED','SENT');
