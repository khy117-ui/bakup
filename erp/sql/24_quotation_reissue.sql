-- ============================================================================
--  견적서 재발행 · 변경 이력  (2026-09-22)
--
--  운영 DB 에는 app/bootstrap.php 의 schema_upgrade_quotations() 가 견적서 화면을 열 때 자동으로 붙입니다.
--  (이미 있으면 건너뜀) 이 파일은 새로 설치할 때와 기록용입니다.
--
--  · [발행] 을 누르면 상태가 발송(SENT) 이 되고 발행 시각 · 횟수가 기록됩니다.
--  · 발행 뒤 내용을 고치면 changed_at 이 찍혀 "재발행 필요" 가 표시되고, [재발행] 하면 차수(revision)가 올라
--    출력물에 REV. 로 표시됩니다. 발행 · 재발행 · 수정은 activity_logs 에 이전값 · 이후값 · 사유와 함께 남습니다.
-- ============================================================================

SET NAMES utf8mb4;

ALTER TABLE quotations ADD COLUMN issued_at DATETIME NULL
  COMMENT '마지막 발행(발송 · 재발행) 시각' AFTER status;
ALTER TABLE quotations ADD COLUMN issue_count INT UNSIGNED NOT NULL DEFAULT 0
  COMMENT '발행 횟수' AFTER issued_at;
ALTER TABLE quotations ADD COLUMN revision INT UNSIGNED NOT NULL DEFAULT 1
  COMMENT '발행본 차수 (재발행하면 +1, 출력물에 REV. 로 표시)' AFTER issue_count;
ALTER TABLE quotations ADD COLUMN reissue_reason VARCHAR(255) NULL
  COMMENT '마지막 재발행 사유' AFTER revision;
ALTER TABLE quotations ADD COLUMN changed_at DATETIME NULL
  COMMENT '발행 뒤 내용이 바뀐 시각 — 재발행 필요 표시용' AFTER reissue_reason;

UPDATE quotations SET issue_count = 1, issued_at = COALESCE(issued_at, updated_at)
 WHERE status IN ('SENT','ACCEPTED','REJECTED','EXPIRED') AND issue_count = 0;
