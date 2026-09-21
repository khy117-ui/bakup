<?php
/**
 * (닫음) 예전 홈페이지 게시판 관리 화면.
 * 공지사항 · Q&A 는 이제 ERP 의 '홈페이지 게시판' 에서 로그인 · 권한 확인 후 관리합니다 (같은 board_post 표).
 * 이 주소는 로그인 없이 글을 고치거나 지울 수 있어 2026-09-19 에 닫았습니다.
 */
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
$b = ($_GET['board'] ?? '') === 'qna' ? 'qna' : 'notice';
header('Location: ../erp/index.php?p=boards&b=' . $b, true, 302);
exit;
