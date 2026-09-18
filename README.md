# 굿배송항공 (GOODPOST) 홈페이지 리뉴얼

postgood.co.kr 리뉴얼 시안(시안 C · 라이트 미니멀)을 반응형 정적 사이트로 구현한 소스입니다.
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
assets/data/rates.xlsx     요금표 원본 엑셀 (이 파일만 교체하면 요금 반영)
admin/rates.html           관리자 페이지 · 게시판(ERP) 관리 링크 / 요금표 교체 안내 · 미리보기
admin/config.js            ERP 게시판(공지사항 · Q&A) 링크 주소 설정
assets/img/                로고 · 사진 자산 (백업 사이트 이미지에서 추출)
```

정적 HTML이므로 별도 빌드 없이 웹서버에 그대로 올리면 됩니다. 로컬 확인은 `python3 -m http.server` 로 가능합니다.

## 요금표 관리 (엑셀 파일 교체)

모든 요금표는 `assets/data/rates.xlsx` 한 파일에서 읽어옵니다. 페이지가 열릴 때 브라우저가 이 파일을 내려받아 표를 그리므로(SheetJS, `assets/js/vendor/xlsx.full.min.js`), 개발자 없이 엑셀만 교체하면 요금이 즉시 바뀝니다.

1. `assets/data/rates.xlsx` 를 내려받아 요금 숫자만 수정합니다. 시트 이름 · 첫 행(제목) · 첫 열(중량/국가)은 그대로 둡니다.
2. `admin/rates.html` 에서 파일을 선택하면 업로드 전에 표 모양을 미리 볼 수 있습니다.
3. 같은 이름으로 `assets/data/rates.xlsx` 를 덮어써 올리면 국제특송(EMS · DHL) · 중량물특송 · 국제특송지역표 페이지에 바로 반영됩니다.

시트 구성: `EMS_비서류`, `EMS_서류`, `EMS_발송조건`, `DHL_수출_비서류`, `DHL_수출_서류`, `DHL_수입_비서류`, `DHL_수입_서류`, `중량물_수출`, `중량물_수입`, `지역표` (첫 시트 `안내`에 설명 포함). EMS · DHL 요금 숫자는 기존 사이트 DB에 있던 값이라 현재 비어 있으며, 빈 칸은 "-" 로 표시됩니다.

## 요금표 관리 페이지 접근 제한 (서버 배포 시)

`admin/` 폴더(요금표 관리 페이지 · 업로드 엔드포인트)는 서버에서 **허용 IP + 관리자 로그인** 두 조건을 모두 만족할 때만 열리도록 설정 파일을 함께 넣어 두었습니다. 사용하는 서버에 맞는 파일 하나만 적용하면 됩니다.

| 서버 | 파일 | 할 일 |
|---|---|---|
| Apache | `admin/.htaccess` | 허용 IP를 사무실 고정 IP로 교체, `htpasswd -c /etc/apache2/goodpost.htpasswd admin` 으로 계정 생성 |
| IIS (Windows) | `admin/web.config` | 허용 IP 교체, IIS "IP 및 도메인 제한"·"기본 인증" 기능 설치 후 관리자 계정 지정 |
| nginx | `deploy/nginx-admin.conf` | server 블록에 붙여 넣고 허용 IP 교체, `htpasswd -c /etc/nginx/goodpost.htpasswd admin` |

- 허용 IP 기본값은 기존 관리자 시스템(ADM)에 등록돼 있던 사무실 IP를 옮겨 둔 것이므로 현재 값으로 확인 후 교체하세요.
- `admin/.htaccess` 맨 위의 `Require expr "%{HTTP_HOST} =~ /\.mycafe24\.ai$/"` 한 줄은 AI SPACE 미리보기(`*.mycafe24.ai`)에서만 관리 페이지를 열어 두기 위한 예외입니다. **실서버에 올릴 때는 이 줄을 삭제**해야 IP · 인증 제한이 온전히 적용됩니다.
- 공개 페이지가 읽는 `assets/data/rates.xlsx` 는 제한 대상이 아닙니다. 요금표 **관리 화면과 업로드 경로만** 막습니다.
- PHP 호스팅이면 `admin/upload.php` 로 관리 페이지에서 바로 엑셀을 교체할 수 있습니다. 파일 안 `ADMIN_TOKEN` 에 긴 무작위 문자열을 넣으면 IP·인증에 더해 토큰까지 3중으로 확인하고, 교체 전 파일은 `assets/data/backup/` 에 날짜별로 보관됩니다. PHP가 없는 정적 호스팅에서는 이 폼 대신 FTP로 덮어쓰면 됩니다.

## 연동이 필요한 부분

- **게시판 관리(ERP)**: 관리자 페이지의 공지사항 · Q&A 버튼은 `admin/config.js` 의 주소로 새 창을 엽니다. 기본값은 ERP 첫 화면(`https://postgood.co.kr/erp/`)이므로 게시판별 목록 · 작성 주소로 교체하세요.
- **게시판(공지사항 · Q&A · 견적문의)**: 목록은 예시 데이터이며 `[등록일]`, `[조회수]`는 DB 연동 후 채워집니다.
- **폼(픽업예약 · 로그인 · 회원가입 · 화물추적 · 결제)**: 마크업과 프론트 동작만 구현되어 있고 전송 대상 API는 비어 있습니다.
- **개인정보취급방침**의 주민등록번호 수집 항목은 현행법 검토가 필요합니다.

## 브랜드 · 콘텐츠 기준

- 색상: 네이비 `#0B2A5B`, 브랜드 블루 `#1B56B8`, 스카이 `#6FB1FF`, 배경 `#F5F8FC`
- 서체: Noto Sans KR(본문) + Montserrat(영문 라벨), Google Fonts 로드
- 운송사 표기는 DHL · UPS · FedEx · EMS 기준이며, 카카오톡 상담 버튼은 모든 페이지 좌측 하단에 고정되어 `https://pf.kakao.com/_xnMMxcd` 로 연결됩니다.
