-- ============================================================================
--  계정별 권한 · 삭제 승인  (2026-09-18)
--
--  운영 DB 에는 app/access.php 의 schema_upgrade_accounts() 가 로그인할 때 자동으로 붙입니다.
--  (이미 있으면 건너뜀) 이 파일은 새로 설치할 때와 기록용입니다.
-- ============================================================================

SET NAMES utf8mb4;

ALTER TABLE admins ADD COLUMN must_change_pw TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '1 = 다음 로그인 때 비밀번호를 바꿔야 함' AFTER password_hash;
ALTER TABLE admins ADD COLUMN perm_custom TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '1 = 역할 대신 계정별 권한(admin_permissions)을 씀' AFTER role_code;

CREATE TABLE admin_permissions (
  admin_id      BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (admin_id, permission_id),
  CONSTRAINT fk_ap_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
  CONSTRAINT fk_ap_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='계정별 권한 — admins.perm_custom = 1 인 계정만 씀';

CREATE TABLE delete_requests (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  route          VARCHAR(40)  NOT NULL COMMENT '화면',
  act            VARCHAR(30)  NOT NULL COMMENT '동작 (remove / cancel / hide)',
  target_id      BIGINT       NOT NULL DEFAULT 0,
  target_label   VARCHAR(255) NULL,
  reason         VARCHAR(500) NULL,
  payload_json   MEDIUMTEXT   NOT NULL COMMENT '요청 당시 보낸 내용 — 승인하면 그대로 다시 실행',
  query_json     TEXT         NOT NULL,
  status         VARCHAR(12)  NOT NULL DEFAULT 'PENDING'
                 COMMENT 'PENDING 대기 / DONE 처리됨 / FAILED 처리 실패 / REJECTED 반려 / WITHDRAWN 철회',
  requested_by   BIGINT UNSIGNED NOT NULL,
  requested_name VARCHAR(50)  NULL,
  requested_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_by     BIGINT UNSIGNED NULL,
  decided_name   VARCHAR(50)  NULL,
  decided_at     DATETIME     NULL,
  decision_note  VARCHAR(500) NULL,
  PRIMARY KEY (id),
  KEY ix_dr_status (status, requested_at),
  KEY ix_dr_target (route, act, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='삭제 · 취소 승인 요청';

INSERT INTO permissions (code, group_ko, name_ko) VALUES
  ('sys.delete.direct',   '시스템',   '삭제·취소 바로 실행 (승인 없이)'),
  ('sys.delete.approve',  '시스템',   '삭제·취소 요청 승인'),
  ('sys.settings.write',  '시스템',   '환경설정 수정'),
  ('sys.backup',          '시스템',   '백업 / 복구'),
  ('logi.tracking.write', '물류관리', '배송추적 등록/완료처리')
ON DUPLICATE KEY UPDATE name_ko = VALUES(name_ko);

INSERT IGNORE INTO role_permissions (role_code, permission_id)
SELECT r.role_code, p.id
  FROM permissions p
  JOIN (SELECT 'MANAGER' AS role_code UNION ALL SELECT 'LOGISTICS') r
 WHERE p.code = 'logi.tracking.write';

-- NAS 서류 백업 열쇠 (NAS 가 가져가는 방식) — 해시만 저장. 운영 DB 에는 schema_upgrade_docs() 가 붙임
CREATE TABLE IF NOT EXISTS backup_keys (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  key_hash     CHAR(64)     NOT NULL,
  label        VARCHAR(100) NULL,
  created_by   BIGINT UNSIGNED NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME     NULL,
  last_ip      VARCHAR(45)  NULL,
  revoked_at   DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bk_hash (key_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='NAS 서류 백업 열쇠';

INSERT INTO document_types (code, name, sort_order)
SELECT 'ETC', '기타 서류', 99 FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM document_types WHERE code = 'ETC');
