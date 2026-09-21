// ------------------------------------------------------------------
// 관리자 페이지 외부 링크 설정
//   ERP 게시판 주소가 바뀌면 이 파일의 주소만 고치면 됩니다. (HTML 수정 불필요)
//   list  : 글 목록 · 수정 · 삭제 화면
//   write : 새 글 작성 화면 (별도 주소가 없으면 list 와 같게 두세요)
// ------------------------------------------------------------------
window.GOODPOST_ADMIN = {
  erp: '../erp/',
  boards: {
    notice: { label: '공지사항', list: '../erp/index.php?p=boards&b=notice', write: '../erp/index.php?p=boards&b=notice&new=1' },
    qna:    { label: 'Q&A',     list: '../erp/index.php?p=boards&b=qna',    write: '../erp/index.php?p=boards&b=qna&new=1' }
  }
};
