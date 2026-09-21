-- ============================================================================
--  청구서 재발행 · 변경 이력  (2026-09-22)
--
--  운영 DB 에는 app/bootstrap.php 의 schema_upgrade_invoices() 가 청구 화면을 열 때 자동으로 붙입니다.
--  (이미 있으면 건너뜀) 이 파일은 새로 설치할 때와 기록용입니다.
--
--  · 발행된 청구서도 금액 · 내용을 고친 뒤 [재발행] 할 수 있습니다.
--  · 발행 · 재발행 · 내용 수정 · 전표/조정항목 추가 · 제외는 모두 activity_logs 에
--    이전값(before_value) · 이후값(after_value) · 사유(reason) 와 함께 남습니다.
-- ============================================================================

SET NAMES utf8mb4;

ALTER TABLE invoices ADD COLUMN issued_at DATETIME NULL
  COMMENT '마지막 발행(재발행) 시각' AFTER status;
ALTER TABLE invoices ADD COLUMN issue_count INT UNSIGNED NOT NULL DEFAULT 0
  COMMENT '발행 횟수 (2 이상이면 재발행됨)' AFTER issued_at;
ALTER TABLE invoices ADD COLUMN reissue_reason VARCHAR(255) NULL
  COMMENT '마지막 재발행 사유' AFTER issue_count;
ALTER TABLE invoices ADD COLUMN changed_at DATETIME NULL
  COMMENT '발행 뒤 내용(전표 · 조정항목 · 날짜)이 바뀐 시각 — 재발행 필요 표시용' AFTER reissue_reason;

-- 이미 발행된 청구서는 1회 발행으로 봅니다
UPDATE invoices SET issue_count = 1, issued_at = COALESCE(issued_at, updated_at)
 WHERE status <> 'DRAFT' AND issue_count = 0;
