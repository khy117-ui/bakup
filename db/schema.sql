-- 굿배송항공 홈페이지 게시판 테이블 (MySQL 8 / MariaDB)
-- includes/db.php 가 첫 요청 때 자동으로 만들지만, ERP 등 다른 프로그램이 같은 테이블을 쓸 때 참고하세요.
--   board      : 'notice'(공지사항) | 'qna'(Q&A)
--   pinned     : 1 이면 목록 맨 위 "공지" 고정
--   is_secret  : 1 이면 비밀글 (본문은 비밀번호 확인 또는 관리자만)
--   password   : 비밀글 비밀번호 (PHP password_hash 값)
--   answer     : Q&A 관리자 답변 (HTML), answered_at 답변 시각
CREATE TABLE IF NOT EXISTS board_post (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    board       VARCHAR(20)  NOT NULL,
    title       VARCHAR(200) NOT NULL,
    content     MEDIUMTEXT   NOT NULL,
    writer      VARCHAR(50)  NOT NULL DEFAULT '관리자',
    views       INT UNSIGNED NOT NULL DEFAULT 0,
    pinned      TINYINT(1)   NOT NULL DEFAULT 0,
    is_secret   TINYINT(1)   NOT NULL DEFAULT 0,
    password    VARCHAR(255) NULL,
    answer      MEDIUMTEXT   NULL,
    answered_at DATETIME     NULL,
    created_at  DATETIME     NOT NULL,
    updated_at  DATETIME     NOT NULL,
    INDEX idx_board (board, pinned, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
