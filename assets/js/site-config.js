/* ------------------------------------------------------------------
 * GOODPOST 홈페이지 공개 설정: 게시판(ERP) 연동 주소
 *   - 공지사항 · Q&A 목록/본문은 ERP 서버의 게시판 API 에서 읽어옵니다.
 *   - 실서버(postgood.co.kr)와 ERP(postgood.co.kr/erp/)가 같은 도메인이므로
 *     별도의 CORS 설정 없이 동작합니다.
 *   - 미리보기(mycafe24.ai · localhost)에서는 ERP 에 접근할 수 없으므로
 *     같은 형식의 예시 JSON(assets/data/board/*.json)을 대신 읽습니다.
 *
 * ERP 가 제공해야 하는 API 형식 (README "게시판 API 형식" 참고)
 *   목록: GET {api}?page=1&size=15&q=검색어
 *         → { "total": 46, "page": 1, "size": 15,
 *             "items": [ { "id": 46, "title": "…", "date": "2025-06-10",
 *                          "views": 2982, "writer": "관리자", "pinned": true } ] }
 *   본문: GET {api}?id=46
 *         → { "id": 46, "title": "…", "date": "…", "views": 2982, "writer": "…",
 *             "content": "<p>본문 HTML 또는 텍스트</p>",
 *             "files": [ { "name": "안내문.pdf", "url": "https://…" } ] }
 * ------------------------------------------------------------------ */
(function () {
  var host = location.hostname;
  var preview = location.protocol === 'file:' || host === 'localhost' || host === '127.0.0.1' || /\.mycafe24\.ai$/.test(host);
  var erp = 'https://postgood.co.kr/erp/';
  window.GOODPOST_SITE = {
    preview: preview,
    boards: {
      notice: {
        label: '공지사항',
        api: preview ? 'assets/data/board/notice.json' : erp + 'api/notice',   // ERP 공지사항 목록/본문 API
        link: erp + 'notice',                                                   // ERP 게시판 화면 (오류 시 안내 링크)
        pageSize: 15
      },
      qna: {
        label: 'Q&A',
        api: preview ? 'assets/data/board/qna.json' : erp + 'api/qna',
        link: erp + 'qna',
        write: erp + 'qna',                                                     // '글쓰기' 버튼 연결 주소
        pageSize: 15
      }
    }
  };
})();
