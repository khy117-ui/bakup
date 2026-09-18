/* ------------------------------------------------------------------
 * GOODPOST 홈페이지 공개 설정: 게시판 API 주소
 *   - 공지사항 · Q&A 목록/본문은 이 서버의 api/board.php 가 DB(board_post 테이블)에서 읽어 JSON 으로 내려줍니다.
 *     (MySQL 환경변수가 주입되면 MySQL, 없으면 영속 폴더의 SQLite — includes/db.php 참고)
 *   - 글 관리는 admin/posts.php, 고객 Q&A 등록은 helpdesk/qna-write.html → api/board.php(POST)
 *   - ERP(/erp/)가 같은 DB 의 board_post 테이블을 읽고 쓰면 홈페이지에 그대로 반영됩니다.
 *   - 다른 서버의 API 를 쓰려면 api 값을 절대 주소(https://…)로 바꾸면 됩니다. (형식: README "게시판 API 형식")
 * ------------------------------------------------------------------ */
(function () {
  var erp = 'https://postgood.co.kr/erp/';
  window.GOODPOST_SITE = {
    boards: {
      notice: {
        label: '공지사항',
        api: 'api/board.php?board=notice',   // 사이트 루트 기준 상대 주소 (board.js 가 data-root 를 붙임)
        link: erp + 'notice',                 // 불러오기 실패 시 안내 링크
        pageSize: 15
      },
      qna: {
        label: 'Q&A',
        api: 'api/board.php?board=qna',
        link: erp + 'qna',
        write: 'qna-write.html',              // '글쓰기' 버튼 (helpdesk/ 기준 상대 주소)
        pageSize: 15
      }
    }
  };
})();
