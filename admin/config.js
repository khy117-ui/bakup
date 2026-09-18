// ------------------------------------------------------------------
// 관리자 페이지 외부 링크 설정
//   ERP 게시판 주소가 바뀌면 이 파일의 주소만 고치면 됩니다. (HTML 수정 불필요)
//   list  : 글 목록 · 수정 · 삭제 화면
//   write : 새 글 작성 화면 (별도 주소가 없으면 list 와 같게 두세요)
//   ※ 아래 기본값은 ERP 첫 화면입니다. 게시판별 실제 주소로 교체하세요.
// ------------------------------------------------------------------
window.GOODPOST_ADMIN = {
  erp: 'https://postgood.co.kr/erp/',
  boards: {
    notice: { label: '공지사항', list: 'https://postgood.co.kr/erp/', write: 'https://postgood.co.kr/erp/' },
    qna:    { label: 'Q&A',     list: 'https://postgood.co.kr/erp/', write: 'https://postgood.co.kr/erp/' }
  }
};
