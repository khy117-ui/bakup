# 굿배송항공 (GOODPOST) 홈페이지 리뉴얼

굿배송항공 홈페이지(good-post.co.kr, 구 postgood.co.kr) 리뉴얼 시안(시안 C · 라이트 미니멀)을 반응형 정적 사이트로 구현한 소스입니다.
기존 ASP 사이트 백업(www.zip)의 메뉴 구조와 본문 콘텐츠를 그대로 옮겼고, 데스크톱(1280px 컨테이너)과 모바일(390px)에서 모두 동작합니다.

## 구성

```
index.html                 메인
company/                   회사소개 · 인사말 / 조직도 / 글로벌네트워크
service/                   서비스소개 · 국제특송(EMS·DHL 요금표) / 중량물특송 / 국제특송지역표
                                     항공화물 / 항공카고화물 / 수출포장
                                     해상운송 / FCL·LCL / Door to Door / 전시회 및 미술품
guide/                     해외특송가이드 · 수출입가이드 / 중국·베트남 / 미국 / 미얀마·태국 / 유럽
helpdesk/                  고객센터 · 공지사항 / Q&A / 견적문의 / 부피계산법 / 화물추적 / 해외운송비결제
member/                    온라인접수 · 픽업예약 / 회원가입 / 로그인 / 이용약관 / 개인정보취급방침
assets/css/style.css       공통 스타일 (디자인 토큰, 컴포넌트, 반응형 미디어쿼리)
assets/js/main.js          모바일 메뉴, 홈 조회 탭, 부피계산기, 해외운송비 합계, 지역표 검색, 운송사 선택
assets/js/rates.js         요금표 엑셀 로더 (rates.xlsx → 표 렌더링, 관리자 미리보기)
assets/js/site-config.js   게시판 API 주소 설정
assets/js/board.js         공지사항 · Q&A 목록/본문 로더 (API → 표 렌더링, 검색 · 페이징 · 비밀글 · 답변)
api/board.php              게시판 공개 API (목록 · 본문 · Q&A 등록) — DB 에서 읽음
includes/db.php            DB 연결 (MySQL 환경변수 → MySQL, 없으면 영속 폴더 SQLite) · 테이블 자동 생성 · 예시 글 시드
db/schema.sql              board_post 테이블 정의 (ERP 등 다른 프로그램이 같은 테이블을 쓸 때 참고)
db/seed/                   테이블이 비어 있을 때 넣는 예시 글
admin/posts.php            게시판 관리 (작성 · 수정 · 삭제 · Q&A 답변)
helpdesk/qna-write.html    고객 Q&A 글쓰기
assets/data/rates.xlsx     요금표 원본 엑셀 (이 파일만 교체하면 요금 반영)
admin/rates.html           관리자 페이지 · 게시판 관리 / 요금표 교체 안내 · 미리보기
admin/config.js            ERP 게시판(공지사항 · Q&A) 링크 주소 설정
assets/img/                로고 · 사진 자산 (백업 사이트 이미지에서 추출)
```

게시판(api/, admin/posts.php)만 PHP 8 + PDO 를 쓰고 나머지는 정적 HTML 입니다. 별도 빌드 없이 PHP 가 되는 웹서버에 그대로 올리면 됩니다. 로컬 확인은 `php -S 127.0.0.1:8000` 으로 가능합니다.

## 요금표 관리 (엑셀 파일 교체)

모든 요금표는 `assets/data/rates.xlsx` 한 파일에서 읽어옵니다. 페이지가 열릴 때 브라우저가 이 파일을 내려받아 표를 그리므로(SheetJS, `assets/js/vendor/xlsx.full.min.js`), 개발자 없이 엑셀만 교체하면 요금이 즉시 바뀝니다.

1. `assets/data/rates.xlsx` 를 내려받아 요금 숫자만 수정합니다. 시트 이름 · 첫 행(제목) · 첫 열(중량/국가)은 그대로 둡니다.
2. `admin/rates.html` 에서 파일을 선택하면 업로드 전에 표 모양을 미리 볼 수 있습니다.
3. 같은 이름으로 `assets/data/rates.xlsx` 를 덮어써 올리면 국제특송(EMS · DHL) · 중량물특송 · 국제특송지역표 페이지에 바로 반영됩니다.

시트 구성: `EMS_비서류`, `EMS_서류`, `EMS_발송조건`, `DHL_수출_비서류`, `DHL_수출_서류`, `DHL_수입_비서류`, `DHL_수입_서류`, `중량물_수출`, `중량물_수입`, `지역표` (첫 시트 `안내`에 설명 포함). EMS · DHL 요금 숫자는 기존 사이트 DB에 있던 값이라 현재 비어 있으며, 빈 칸은 "-" 로 표시됩니다.

## 관리자 페이지 접근 제한 · 비밀번호 (AI SPACE 배포)

`/admin/` 폴더(요금표 · 게시판 관리, 업로드 엔드포인트)는 `admin/.htaccess` 로 보호됩니다.

1. **비밀번호 만들기(최초 1회)**: AI SPACE 콘솔(또는 `project_env`)에 환경변수 `ADMIN_SETUP_TOKEN` 을 설정한 뒤, 기본 제공 도메인으로 `https://khy117-bakup.mycafe24.ai/admin/setup-password.php` 를 열어 토큰 · 아이디 · 비밀번호를 입력합니다. 비밀번호 파일은 영속 폴더 `/app/user_data/goodpost.htpasswd` 에 저장되어 재배포해도 유지됩니다.
2. **그 뒤로는** 어느 도메인(good-post.co.kr · 기본 도메인)에서 접속하든 브라우저 로그인 창이 뜨고, 아이디 · 비밀번호가 맞아야 `/admin/` 이 열립니다. 비밀번호 변경은 로그인 후 같은 페이지에서 합니다.
3. 비밀번호 파일이 없는 동안에는 기본 제공 도메인(`*.mycafe24.ai`)과 `.htaccess` 의 허용 IP 에서만 열립니다. 사무실 고정 IP 에서만 열고 싶으면 `.htaccess` 의 주석 처리된 `RequireAll` 블록을 풀어 IP 를 넣으세요.
4. `admin/upload.php` 는 로그인 + (설정 시) 업로드 토큰으로 보호되며, `ALLOWED_IPS` 를 채우면 IP 도 추가로 검사합니다.
5. 다른 서버(IIS · nginx)에 올릴 때는 `admin/web.config`, `deploy/nginx-admin.conf` 를 참고하세요.

## 연동이 필요한 부분

- **게시판(공지사항 · Q&A)**: 이 서버의 DB(`board_post`)에 저장되며 `admin/posts.php` 에서 관리합니다. ERP(/erp/)가 같은 DB 를 쓰면 같은 테이블을 읽고 쓰면 됩니다(아래 "게시판 DB · API").
- **견적문의**: 목록은 예시 데이터입니다.
- **폼(픽업예약 · 로그인 · 회원가입 · 화물추적 · 결제)**: 마크업과 프론트 동작만 구현되어 있고 전송 대상 API는 비어 있습니다.
- **개인정보취급방침**의 주민등록번호 수집 항목은 현행법 검토가 필요합니다.

## 게시판 DB · API

공지사항 · Q&A 글은 DB 테이블 `board_post` 한 곳에 저장됩니다(`db/schema.sql`).

- **DB 연결**: `includes/db.php` 가 서버 환경변수 `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` 를 읽어 MySQL 에 접속합니다(AI SPACE · 호스팅이 자동 주입). 환경변수가 없으면 영속 폴더(`/app/user_data`, 없으면 `data/`)에 SQLite 파일을 만들어 동작하므로 DB 를 붙이기 전에도 게시판이 작동하고, MySQL 이 연결되면 자동으로 전환됩니다. 관리 화면 우측 상단에 현재 저장소(MySQL / SQLite)가 표시됩니다.
- **테이블 · 예시 글**: 첫 요청 때 테이블이 없으면 만들고, 비어 있으면 `db/seed/*.json` 의 예시 글을 넣습니다. 실제 글을 등록하기 전에 관리 화면에서 예시 글을 지우면 됩니다.
- **관리**: `admin/posts.php` (공지사항 · Q&A 탭, 작성 · 수정 · 삭제 · 공지 고정 · 비밀글 · Q&A 답변). `/admin/` 접근 제한(허용 IP + 관리자 인증)이 그대로 적용됩니다.
- **고객 Q&A 등록**: `helpdesk/qna-write.html` → `api/board.php`(POST). 비밀글은 비밀번호(해시 저장)를 아는 사람과 관리자만 본문을 볼 수 있습니다. 공지사항은 공개 등록이 막혀 있습니다.
- **ERP 연동**: ERP 가 같은 DB 의 `board_post` 에 INSERT / UPDATE / DELETE 하면 홈페이지에 즉시 반영됩니다. 컬럼 의미는 `db/schema.sql` 주석 참고. 관리자 페이지의 ERP 링크는 같은 서버의 `/erp/` 상대 주소이며 `admin/config.js` 에서 바꿉니다.

공개 API (`api/board.php`, 홈페이지 JS 가 사용 · 다른 서버 API 로 바꾸려면 `assets/js/site-config.js` 의 `api` 값을 교체):

| 요청 | 응답(JSON) |
|---|---|
| 목록 `GET api/board.php?board=notice&page=1&size=15&q=검색어` | `{"total":46,"page":1,"size":15,"items":[{"id":46,"title":"제목","date":"2025-06-10","views":2982,"writer":"관리자","pinned":true,"secret":false,"answered":false}]}` |
| 본문 `GET api/board.php?board=qna&id=46[&pw=비밀번호]` | `{"id":46,"title":"…","date":"…","views":…,"writer":"…","content":"<p>본문</p>","answer":"<p>답변</p>","answered_at":"2026-09-18"}` — 비밀글에 비밀번호가 없거나 틀리면 `403 {"error":"secret"}` |
| 등록 `POST api/board.php` (`board=qna&title&writer&content&secret=1&password`) | `201 {"ok":true,"id":47}` |

## 브랜드 · 콘텐츠 기준

- 색상: 네이비 `#0B2A5B`, 브랜드 블루 `#1B56B8`, 스카이 `#6FB1FF`, 배경 `#F5F8FC`
- 서체: Noto Sans KR(본문) + Montserrat(영문 라벨), Google Fonts 로드
- 운송사 표기는 DHL · UPS · FedEx · EMS 기준이며, 카카오톡 상담 버튼은 모든 페이지 좌측 하단에 고정되어 `https://pf.kakao.com/_xnMMxcd` 로 연결됩니다.
