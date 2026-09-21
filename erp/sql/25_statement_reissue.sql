-- ============================================================================
--  거래명세서 재발행 · 변경 이력  (2026-09-22)
--
--  운영 DB 에는 app/bootstrap.php 의 schema_upgrade_statements() 가 명세서 화면을 열 때 자동으로 붙입니다.
--  (이미 있으면 건너뜀) 이 파일은 새로 설치할 때와 기록용입니다.
--
--  · 명세서는 만들 때 금액이 고정됩니다. 수록 전표의 금액이 그 뒤 바뀌면 목록 · 상세에 "재발행 필요" 가 표시되고,
--    [재발행] 하면 현재 전표 금액으로 다시 계산해 차수(revision)가 올라 출력물에 REV. 로 표시됩니다.
--  · 발행 · 재발행은 activity_logs 에 이전값 · 이후값 · 사유와 함께 남습니다.
-- ============================================================================

SET NAMES utf8mb4;

ALTER TABLE statements ADD COLUMN issued_at DATETIME NULL
  COMMENT '마지막 발행(재발행) 시각' AFTER status;
ALTER TABLE statements ADD COLUMN issue_count INT UNSIGNED NOT NULL DEFAULT 1
  COMMENT '발행 횟수 (만들 때 1)' AFTER issued_at;
ALTER TABLE statements ADD COLUMN revision INT UNSIGNED NOT NULL DEFAULT 1
  COMMENT '발행본 차수 (재발행하면 +1, 출력물에 REV. 로 표시)' AFTER issue_count;
ALTER TABLE statements ADD COLUMN reissue_reason VARCHAR(255) NULL
  COMMENT '마지막 재발행 사유' AFTER revision;

UPDATE statements SET issued_at = created_at WHERE issued_at IS NULL;
