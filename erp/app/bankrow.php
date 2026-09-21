<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

/**
 * 은행내역 한 줄 ↔ 입출금 거래 연결.
 *
 * 은행내역 가져오기에서 [입금처리] / [출금처리] 를 누르면 ?bank_row=ID 가 붙어 등록 화면이 열리고,
 * 일자 · 금액 · 계좌 · 보낸분이 미리 채워집니다. 저장하는 **같은 트랜잭션 안에서** 그 줄을
 * '처리완료' 로 바꾸고 만든 거래번호를 적어 둡니다. 거래를 취소하면 줄도 다시 미처리로 돌아옵니다.
 */

/** 아직 처리 안 한 은행내역 한 줄 — dir 'IN' 이면 입금 줄, 'OUT' 이면 출금 줄만 */
function bank_row_open(int $id, string $dir): ?array
{
    if ($id <= 0) { return null; }
    $col = $dir === 'OUT' ? 'out_amount' : 'in_amount';
    $st = db()->prepare("SELECT r.*, b.bank_name, b.account_no
                           FROM bank_import_rows r
                           LEFT JOIN business_bank_accounts b ON b.id = r.bank_account_id
                          WHERE r.id = ? AND r.match_status IN ('UNMATCHED','SUGGESTED') AND r.$col > 0");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) { return null; }
    $f = entity_filter();
    if ($f !== null && $f !== (int)$row['business_entity_id']) { return null; }
    return $row;
}

/**
 * 저장 트랜잭션 안에서 부릅니다. 줄을 잠그고 다시 확인한 뒤 처리완료로 바꿉니다.
 * $amounts 중 하나라도 은행 금액과 맞으면 통과 (계좌이체는 '이체액' 과 '이체액+수수료' 둘 다 봅니다).
 * 돌려주는 값은 은행내역 묶음 번호 — 저장 후 그 목록으로 돌아갈 때 씁니다.
 */
function bank_row_link(PDO $pdo, int $rowId, string $dir, int $txnId, int $entId, string $date, array $amounts): int
{
    $st = $pdo->prepare('SELECT * FROM bank_import_rows WHERE id = ? FOR UPDATE');
    $st->execute([$rowId]);
    $r = $st->fetch();
    if (!$r) { throw new RuntimeException('은행내역 줄을 찾을 수 없습니다.'); }
    if (!in_array($r['match_status'], ['UNMATCHED', 'SUGGESTED'], true)) {
        throw new RuntimeException('이 은행내역은 이미 처리(또는 무시)됐습니다. 은행내역 화면을 새로 고쳐 보세요.');
    }
    if ((int)$r['business_entity_id'] !== $entId) {
        throw new RuntimeException('은행내역과 다른 사업자로 등록하려고 합니다. 사업자를 은행내역과 같게 고르세요.');
    }
    $bank = (float)($dir === 'OUT' ? $r['out_amount'] : $r['in_amount']);
    if ($bank <= 0) {
        throw new RuntimeException($dir === 'OUT' ? '이 은행내역은 출금 줄이 아닙니다.' : '이 은행내역은 입금 줄이 아닙니다.');
    }
    $ok = false;
    foreach ($amounts as $a) {
        if (abs((float)$a - $bank) < 0.5) { $ok = true; break; }
    }
    if (!$ok) {
        throw new RuntimeException('은행내역 금액은 ' . money($bank) . '원인데 ' . money((float)reset($amounts))
            . '원으로 등록하려고 합니다. 금액을 은행내역과 같게 맞추세요.');
    }
    if (substr((string)$r['txn_at'], 0, 10) !== $date) {
        // 날짜가 다른 건 막지 않습니다 (주말 입금을 월요일로 잡는 경우 등) — 기록만 남깁니다
        error_log('은행내역 ' . $rowId . ' 날짜 다름: 은행 ' . $r['txn_at'] . ' / 등록 ' . $date);
    }
    $pdo->prepare("UPDATE bank_import_rows
                      SET match_status = 'CONFIRMED', transaction_id = ?, confirmed_at = NOW(), confirmed_by = ?
                    WHERE id = ?")
        ->execute([$txnId, $_SESSION['admin_id'] ?? null, $rowId]);
    return (int)$r['import_id'];
}

/** 거래를 취소하면 연결된 은행내역 줄을 다시 미처리로 (취소 트랜잭션 안에서) */
function bank_row_unlink(PDO $pdo, int $txnId): int
{
    $st = $pdo->prepare("UPDATE bank_import_rows
                            SET match_status = IF(suggested_company_id IS NULL, 'UNMATCHED', 'SUGGESTED'),
                                transaction_id = NULL, confirmed_at = NULL, confirmed_by = NULL
                          WHERE transaction_id = ?");
    $st->execute([$txnId]);
    return $st->rowCount();
}

/** 등록 화면 위에 띄우는 안내 + 폼에 넣을 hidden */
function bank_row_banner(array $r, string $dir): string
{
    $amt = (float)($dir === 'OUT' ? $r['out_amount'] : $r['in_amount']);
    return '<div class="msg" style="background:#EEF6FA;color:#0B4F6C;border:1px solid #BFDCEA">'
        . '🏦 <b>은행내역에서 가져왔습니다</b> — ' . h(substr((string)$r['txn_at'], 0, 16)) . ' · '
        . '<b>' . ($dir === 'OUT' ? '출금 ' : '입금 ') . money($amt) . '원</b>'
        . ($r['counterparty'] ? ' · ' . h($r['counterparty']) : '')
        . ($r['bank_name'] ? ' · ' . h($r['bank_name'] . ' ' . $r['account_no']) : '')
        . '<br><span style="font-size:12px">일자 · 금액 · 계좌 · 이름을 미리 채웠습니다. 저장하면 은행내역의 이 줄이 <b>처리완료</b>로 바뀝니다.'
        . ' 금액은 은행내역과 같아야 저장됩니다.'
        . ' <a href="?p=bank_import&amp;import_id=' . (int)$r['import_id'] . '">은행내역으로 돌아가기</a></span></div>';
}
