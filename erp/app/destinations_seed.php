<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

/**
 * 도착지 표기 → [국가코드(ISO 2자리), 한글 이름].
 * 옛 시스템 IS_SALES.ARRIVAL_N 에 실제로 쓰인 144가지 (2026-09-18 확인) 를 기준으로 만들었습니다.
 * 도시로 적힌 것(NEW YORK · DUBAI · SEOUL …)은 그 나라 코드를 붙입니다.
 * 여기 없는 표기는 도착지 관리 화면에서 직접 적으면 됩니다.
 */
return [
    'AFGHANISTAN' => ['AF', '아프가니스탄'], 'ALBANIA' => ['AL', '알바니아'], 'ALGERIA' => ['DZ', '알제리'],
    'ANDORRA' => ['AD', '안도라'], 'ARGENTINA' => ['AR', '아르헨티나'], 'ARMENIA' => ['AM', '아르메니아'],
    'AUSTRALIA' => ['AU', '호주'], 'AUSTRIA' => ['AT', '오스트리아'], 'AZERBAIJAN' => ['AZ', '아제르바이잔'],
    'BAHRAIN' => ['BH', '바레인'], 'BANGLADESH' => ['BD', '방글라데시'], 'BELARUS' => ['BY', '벨라루스'],
    'BELGIUM' => ['BE', '벨기에'], 'BERMUDA' => ['BM', '버뮤다'], 'BOLIVIA' => ['BO', '볼리비아'],
    'BOTSWANA' => ['BW', '보츠와나'], 'BRAZIL' => ['BR', '브라질'], 'BRUNEI DARUSSALAM' => ['BN', '브루나이'],
    'BULGARIA' => ['BG', '불가리아'], 'CAMBODIA' => ['KH', '캄보디아'], 'CAMEROON' => ['CM', '카메룬'],
    'CANADA' => ['CA', '캐나다'], 'CANARY ISLANDS' => ['ES', '카나리아 제도(스페인)'],
    'CENTRAL AFRICAN REPUBLIC' => ['CF', '중앙아프리카공화국'], 'CHILE' => ['CL', '칠레'], 'CHINA' => ['CN', '중국'],
    'COLOMBIA' => ['CO', '콜롬비아'], 'COSTA RICA' => ['CR', '코스타리카'],
    'CROATIA(LOCAL NAME:HRVATSKA)' => ['HR', '크로아티아'], 'CROATIA' => ['HR', '크로아티아'], 'CUBA' => ['CU', '쿠바'],
    'CYPRUS' => ['CY', '키프로스'], 'CZECH' => ['CZ', '체코'], 'DENMARK' => ['DK', '덴마크'],
    'DOMINICAN REPUBLIC' => ['DO', '도미니카공화국'], 'DUBAI' => ['AE', '두바이(UAE)'], 'ECUADOR' => ['EC', '에콰도르'],
    'EGYPT' => ['EG', '이집트'], 'ESTONIA' => ['EE', '에스토니아'], 'ETHIOPIA' => ['ET', '에티오피아'],
    'FIJI' => ['FJ', '피지'], 'FINLAND' => ['FI', '핀란드'], 'FRANCE' => ['FR', '프랑스'], 'GEORGIA' => ['GE', '조지아'],
    'GERMANY' => ['DE', '독일'], 'GHANA' => ['GH', '가나'], 'GREECE' => ['GR', '그리스'], 'GUAM' => ['GU', '괌'],
    'GUATEMALA' => ['GT', '과테말라'], 'HONG KONG' => ['HK', '홍콩'], 'HUNGARY' => ['HU', '헝가리'],
    'ICELAND' => ['IS', '아이슬란드'], 'INDIA' => ['IN', '인도'], 'INDONESIA' => ['ID', '인도네시아'],
    'IRAN(ISLAMIC REPUBLIC OF)' => ['IR', '이란'], 'IRAN' => ['IR', '이란'], 'IRAQ' => ['IQ', '이라크'],
    'IRELAND' => ['IE', '아일랜드'], 'ISRAEL' => ['IL', '이스라엘'], 'ITALY' => ['IT', '이탈리아'], 'JAPAN' => ['JP', '일본'],
    'JORDAN' => ['JO', '요르단'], 'KAZAKHSTAN' => ['KZ', '카자흐스탄'], 'KENYA' => ['KE', '케냐'], 'KOSOVO' => ['XK', '코소보'],
    'KUWAIT' => ['KW', '쿠웨이트'], 'KYRGYZSTAN' => ['KG', '키르기스스탄'], 'LAOS PEOPLE' => ['LA', '라오스'],
    'LAOS' => ['LA', '라오스'], 'LATVIA' => ['LV', '라트비아'], 'LEBANON' => ['LB', '레바논'], 'LIBYA' => ['LY', '리비아'],
    'LITHUANIA' => ['LT', '리투아니아'], 'LOS ANGELES' => ['US', '로스앤젤레스(미국)'], 'MACAU' => ['MO', '마카오'],
    'MACEDONIA' => ['MK', '북마케도니아'], 'MALAWI' => ['MW', '말라위'], 'MALAYSIA' => ['MY', '말레이시아'],
    'MALDIVES' => ['MV', '몰디브'], 'MALTA' => ['MT', '몰타'], 'MARTINIQUE' => ['MQ', '마르티니크'],
    'MAURITIUS' => ['MU', '모리셔스'], 'MEXICO' => ['MX', '멕시코'], 'MOLDOVA REPUBLIC OF' => ['MD', '몰도바'],
    'MONGOLIA' => ['MN', '몽골'], 'MOROCCO' => ['MA', '모로코'], 'MOZAMBIQUE' => ['MZ', '모잠비크'],
    'MYANMAR' => ['MM', '미얀마'], 'NEPAL' => ['NP', '네팔'], 'NETHERLANDS' => ['NL', '네덜란드'],
    'NETHERLANDS ANTILLES' => ['AN', '네덜란드령 안틸레스'], 'NEW CALEDONIA' => ['NC', '뉴칼레도니아'],
    'NEW YORK' => ['US', '뉴욕(미국)'], 'NEW ZEALAND' => ['NZ', '뉴질랜드'], 'NICARAGUA' => ['NI', '니카라과'],
    'NIGER' => ['NE', '니제르'], 'NIGERIA' => ['NG', '나이지리아'], 'NORWAY' => ['NO', '노르웨이'], 'OMAN' => ['OM', '오만'],
    'PAKISTAN' => ['PK', '파키스탄'], 'PANAMA' => ['PA', '파나마'], 'PAPUA NEW GUINEA' => ['PG', '파푸아뉴기니'],
    'PARAGUAY' => ['PY', '파라과이'], 'PERU' => ['PE', '페루'], 'PHILIPPINES' => ['PH', '필리핀'], 'POLAND' => ['PL', '폴란드'],
    'PORTUGAL' => ['PT', '포르투갈'], 'PUERTO RICO' => ['PR', '푸에르토리코'], 'QATAR' => ['QA', '카타르'],
    'ROMANIA' => ['RO', '루마니아'], 'RUSSIAN FEDERATION' => ['RU', '러시아'], 'RUSSIA' => ['RU', '러시아'],
    'RWANDA' => ['RW', '르완다'], 'SAIPAN' => ['MP', '사이판'], 'SAUDI ARABIA' => ['SA', '사우디아라비아'],
    'SENEGAL' => ['SN', '세네갈'], 'SEOUL' => ['KR', '서울(수입)'], 'SINGAPORE' => ['SG', '싱가포르'],
    'SLOVAKIA' => ['SK', '슬로바키아'], 'SLOVENIA' => ['SI', '슬로베니아'], 'SOUTH AFRICA' => ['ZA', '남아프리카공화국'],
    'SPAIN' => ['ES', '스페인'], 'SRI LANKA' => ['LK', '스리랑카'], 'SUDAN' => ['SD', '수단'], 'SWAZILAND' => ['SZ', '에스와티니'],
    'SWEDEN' => ['SE', '스웨덴'], 'SWITZERLAND' => ['CH', '스위스'], 'SYRIAN ARAB REPUBLIC' => ['SY', '시리아'],
    'TAIWAN' => ['TW', '대만'], 'TAJIKISTAN' => ['TJ', '타지키스탄'], 'TANZANIA' => ['TZ', '탄자니아'],
    'THAILAND' => ['TH', '태국'], 'TUNISIA' => ['TN', '튀니지'], 'TURKEY' => ['TR', '튀르키예'], 'U.K' => ['GB', '영국'],
    'UGANDA' => ['UG', '우간다'], 'UKRAINE' => ['UA', '우크라이나'], 'UNITED ARAB EMIRATES' => ['AE', '아랍에미리트'],
    'UNITED KINGDOM' => ['GB', '영국'], 'UNITED STATES' => ['US', '미국'], 'USA' => ['US', '미국'],
    'URUGUAY' => ['UY', '우루과이'], 'UZBEKISTAN' => ['UZ', '우즈베키스탄'], 'VENEZUELA' => ['VE', '베네수엘라'],
    'VIET NAM' => ['VN', '베트남'], 'VIETNAM' => ['VN', '베트남'], 'YEMEN' => ['YE', '예멘'], 'ZAMBIA' => ['ZM', '잠비아'],
    'ZIMBABWE' => ['ZW', '짐바브웨'], 'KOREA' => ['KR', '한국'], 'SOUTH KOREA' => ['KR', '한국'],
];
