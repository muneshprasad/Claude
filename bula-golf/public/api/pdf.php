<?php
declare(strict_types=1);
/**
 * Signed-agreement PDF and email. Self-contained (no libraries, no GD): a small PDF writer, a PNG reader for the
 * tablet signature, and a mail() sender with the PDF attached. Included by index.php; not meant to be opened directly.
 */

final class MiniPdf {
    public const W = 595.28, H = 841.89, M = 54.0;
    private array $pages = [];
    private string $buf = '';
    private float $y;
    private array $imgs = [];
    /** Helvetica glyph widths for characters 32..126 (per 1000 em), used to wrap lines. */
    private static function widths(): array {
        return array_merge(
            [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278], array_fill(0, 10, 556),
            [278, 278, 584, 584, 584, 556, 1015],
            [667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611],
            [278, 278, 278, 469, 556, 333],
            [556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500],
            [334, 260, 334, 584]
        );
    }
    private array $hw;

    public function __construct() { $this->y = self::H - self::M; $this->hw = self::widths(); }

    public static function conv(string $s): string {
        $t = function_exists('iconv') ? @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s) : false;
        if ($t === false || $t === null) $t = preg_replace('/[^\x20-\x7E\n]/', '?', $s);
        return (string)$t;
    }
    private static function esc(string $s): string { return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $s); }

    public function width(string $s, float $size, bool $bold = false): float {
        $w = 0;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) { $o = ord($s[$i]); $w += ($o >= 32 && $o <= 126) ? $this->hw[$o - 32] : 556; }
        return $w * $size / 1000 * ($bold ? 1.07 : 1.0);
    }

    private function ensure(float $h): void {
        if ($this->y - $h < self::M) { $this->pages[] = $this->buf; $this->buf = ''; $this->y = self::H - self::M; }
    }

    public function gap(float $h): void { $this->y -= $h; }

    /** Wrapped paragraph(s); "\n" starts a new line. */
    public function text(string $s, float $size = 10.5, bool $bold = false, float $gray = 0.0, float $indent = 0.0): void {
        $lead = $size * 1.38;
        $maxw = self::W - 2 * self::M - $indent;
        foreach (explode("\n", self::conv($s)) as $para) {
            $para = trim($para);
            if ($para === '') { $this->ensure($lead); $this->y -= $lead * 0.7; continue; }
            $line = '';
            foreach (preg_split('/ +/', $para) as $word) {
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($line !== '' && $this->width($try, $size, $bold) > $maxw) { $this->emit($line, $size, $bold, $gray, $indent, $lead); $line = $word; }
                else $line = $try;
            }
            if ($line !== '') $this->emit($line, $size, $bold, $gray, $indent, $lead);
        }
    }
    private function emit(string $line, float $size, bool $bold, float $gray, float $indent, float $lead): void {
        $this->ensure($lead);
        $this->y -= $lead;
        $this->buf .= sprintf("BT /F%d %.2F Tf %.2F g %.2F %.2F Td (%s) Tj ET\n", $bold ? 2 : 1, $size, $gray, self::M + $indent, $this->y, self::esc($line));
    }

    public function rule(float $gray = 0.8): void {
        $this->ensure(8); $this->y -= 4;
        $this->buf .= sprintf("%.2F G 0.6 w %.2F %.2F m %.2F %.2F l S\n", $gray, self::M, $this->y, self::W - self::M, $this->y);
        $this->y -= 4;
    }

    /** @param array{w:int,h:int,data:string} $img an RGB image from png_to_rgb() */
    public function image(array $img, float $drawW): void {
        $drawH = $drawW * $img['h'] / $img['w'];
        $this->ensure($drawH + 6);
        $this->imgs[] = $img; $k = count($this->imgs);
        $this->y -= $drawH;
        $this->buf .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im%d Do Q\n", $drawW, $drawH, self::M, $this->y, $k);
        $this->y -= 6;
    }

    public function output(): string {
        $pages = $this->pages; $pages[] = $this->buf;
        $I = count($this->imgs); $P = count($pages);
        $o = [];
        $o[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = []; for ($i = 0; $i < $P; $i++) $kids[] = (6 + $I + 2 * $i) . ' 0 R';
        $o[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $P . ' >>';
        $o[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $o[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $xo = '';
        foreach ($this->imgs as $k => $im) {
            $xo .= '/Im' . ($k + 1) . ' ' . (5 + $k) . ' 0 R ';
            $cs = $im['cs'] ?? 'DeviceRGB'; $flt = !empty($im['dct']) ? 'DCTDecode' : 'FlateDecode'; // JPEGs go in as they are; PNGs were flattened to RGB
            $o[5 + $k] = ['<< /Type /XObject /Subtype /Image /Width ' . $im['w'] . ' /Height ' . $im['h'] . ' /ColorSpace /' . $cs . ' /BitsPerComponent 8 /Filter /' . $flt . ' /Length ' . strlen($im['data']) . ' >>', $im['data']];
        }
        foreach ($pages as $i => $content) {
            $cid = 5 + $I + 2 * $i;
            $o[$cid] = ['<< /Length ' . strlen($content) . ' >>', $content];
            $o[$cid + 1] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> /XObject << %s>> >> /Contents %d 0 R >>', self::W, self::H, $xo, $cid);
        }
        ksort($o);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $off = [];
        foreach ($o as $id => $b) {
            $off[$id] = strlen($out);
            $out .= "$id 0 obj\n" . (is_array($b) ? $b[0] . "\nstream\n" . $b[1] . "\nendstream" : $b) . "\nendobj\n";
        }
        $x = strlen($out); $n = count($o) + 1;
        $out .= "xref\n0 $n\n0000000000 65535 f \n";
        foreach ($off as $p) $out .= sprintf("%010d 00000 n \n", $p);
        return $out . "trailer\n<< /Size $n /Root 1 0 R >>\nstartxref\n$x\n%%EOF";
    }
}

/** 8-bit RGB / RGBA, non-interlaced PNG (what the tablet canvas produces) to flat RGB over white. Null if it is anything else. */
function png_to_rgb(string $png): ?array {
    if (strlen($png) < 33 || substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;
    $pos = 8; $idat = ''; $w = $h = $depth = $ct = 0; $il = 1;
    while ($pos + 8 <= strlen($png)) {
        $len = unpack('N', substr($png, $pos, 4))[1];
        $type = substr($png, $pos + 4, 4); $data = substr($png, $pos + 8, $len);
        if ($type === 'IHDR') { $dim = unpack('N2', substr($data, 0, 8)); $w = $dim[1]; $h = $dim[2]; $depth = ord($data[8]); $ct = ord($data[9]); $il = ord($data[12]); }
        elseif ($type === 'IDAT') $idat .= $data;
        elseif ($type === 'IEND') break;
        $pos += 12 + $len;
    }
    if ($depth !== 8 || $il !== 0 || !in_array($ct, [2, 6], true) || $w < 1 || $h < 1 || $w * $h > 3000000) return null;
    $raw = @gzuncompress($idat);
    if ($raw === false) return null;
    $bpp = $ct === 6 ? 4 : 3; $stride = $w * $bpp;
    if (strlen($raw) < ($stride + 1) * $h) return null;
    $prev = array_fill(0, $stride, 0); $rgb = '';
    for ($y = 0; $y < $h; $y++) {
        $base = $y * ($stride + 1);
        $f = ord($raw[$base]);
        $row = array_values(unpack('C*', substr($raw, $base + 1, $stride)));
        if ($f === 1) { for ($i = $bpp; $i < $stride; $i++) $row[$i] = ($row[$i] + $row[$i - $bpp]) & 255; }
        elseif ($f === 2) { for ($i = 0; $i < $stride; $i++) $row[$i] = ($row[$i] + $prev[$i]) & 255; }
        elseif ($f === 3) { for ($i = 0; $i < $stride; $i++) { $a = $i >= $bpp ? $row[$i - $bpp] : 0; $row[$i] = ($row[$i] + (($a + $prev[$i]) >> 1)) & 255; } }
        elseif ($f === 4) {
            for ($i = 0; $i < $stride; $i++) {
                $a = $i >= $bpp ? $row[$i - $bpp] : 0; $b = $prev[$i]; $c = $i >= $bpp ? $prev[$i - $bpp] : 0;
                $p = $a + $b - $c; $pa = abs($p - $a); $pb = abs($p - $b); $pc = abs($p - $c);
                $row[$i] = ($row[$i] + (($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c))) & 255;
            }
        } elseif ($f !== 0) return null;
        $prev = $row;
        if ($bpp === 4) {
            for ($i = 0; $i < $stride; $i += 4) {
                $al = $row[$i + 3];
                if ($al === 255) { $rgb .= chr($row[$i]) . chr($row[$i + 1]) . chr($row[$i + 2]); }
                else { $k = 255 - $al; $rgb .= chr((int)(($row[$i] * $al + 255 * $k) / 255)) . chr((int)(($row[$i + 1] * $al + 255 * $k) / 255)) . chr((int)(($row[$i + 2] * $al + 255 * $k) / 255)); }
            }
        } else $rgb .= pack('C*', ...$row);
    }
    return ['w' => $w, 'h' => $h, 'data' => gzcompress($rgb, 6)];
}

/** Baseline JPEG as a PDF image (embedded as is, so a photo banner stays small). Null for anything else (progressive, CMYK...). */
function jpeg_for_pdf(string $jpg): ?array {
    if (strlen($jpg) < 4 || substr($jpg, 0, 2) !== "\xFF\xD8") return null;
    $pos = 2; $n = strlen($jpg);
    while ($pos + 4 < $n) {
        if ($jpg[$pos] !== "\xFF") { $pos++; continue; }
        $m = ord($jpg[$pos + 1]);
        if ($m === 0xFF) { $pos++; continue; }
        if ($m === 0xD8 || ($m >= 0xD0 && $m <= 0xD7) || $m === 0x01) { $pos += 2; continue; }
        $len = unpack('n', substr($jpg, $pos + 2, 2))[1];
        if ($m === 0xC0 || $m === 0xC1) { // baseline / extended sequential
            $h = unpack('n', substr($jpg, $pos + 5, 2))[1]; $w = unpack('n', substr($jpg, $pos + 7, 2))[1]; $comps = ord($jpg[$pos + 9]);
            if ($w < 1 || $h < 1 || !in_array($comps, [1, 3], true)) return null;
            return ['w' => $w, 'h' => $h, 'data' => $jpg, 'dct' => true, 'cs' => $comps === 1 ? 'DeviceGray' : 'DeviceRGB'];
        }
        if ($m === 0xC2) return null; // progressive: not supported here
        $pos += 2 + $len;
    }
    return null;
}

/** A stored picture (data URL, PNG or JPEG) as something MiniPdf can place. */
function pdf_image_from_dataurl(string $url): ?array {
    if (strpos($url, 'data:image/png;base64,') === 0) return png_to_rgb((string)base64_decode(substr($url, 22)));
    if (strpos($url, 'data:image/jpeg;base64,') === 0) return jpeg_for_pdf((string)base64_decode(substr($url, 23)));
    return null;
}

/** The signed agreement for one golfer: club, check-in details, the terms they accepted and their signature. */
function build_signed_pdf(string $club, array $ci, array $g, string $terms, int $termsVersion, string $logo = '', string $promo = ''): string {
    $p = new MiniPdf();
    $when = static fn($s) => $s ? date('j M Y, g:i a', strtotime((string)$s)) : '';
    $li = pdf_image_from_dataurl($logo); // the club's logo
    if ($li) { $p->image($li, min(170.0, 46.0 * $li['w'] / $li['h'])); $p->gap(4); }
    $p->text($club, 20, true, 0.1);
    $p->text('Cart and equipment hire agreement', 12.5, false, 0.35);
    $p->rule();
    $p->gap(2);
    $p->text('Check-in reference: #' . $ci['id'] . '     Date: ' . $when($ci['checked_in_at']), 10, false, 0.25);
    $p->text('Golfer: ' . $g['first_name'] . ' ' . $g['last_name'] . (!empty($g['guardian_name']) ? '     (signed by parent/guardian ' . $g['guardian_name'] . ')' : ''), 10.5, true);
    $hire = [];
    $hire[] = (int)$ci['holes'] . ' holes';
    $hire[] = !empty($ci['cart_name']) ? $ci['cart_name'] : 'no cart';
    if (!empty($ci['clubs_name'])) $hire[] = $ci['clubs_name'];
    if (!empty($ci['items'])) $hire[] = $ci['items'];
    if (!empty($ci['bags'])) $hire[] = 'bag ' . $ci['bags'];
    $p->text('Details: ' . implode(', ', $hire), 10, false, 0.25);
    $p->gap(8);
    $p->text('Terms accepted (version ' . $termsVersion . ')', 11, true);
    $p->gap(2);
    $p->text($terms, 9.5, false, 0.15);
    $p->gap(10);
    $p->rule();
    $p->text('Signature', 10, true);
    $img = !empty($g['signature']) && strpos((string)$g['signature'], 'data:image/png;base64,') === 0 ? png_to_rgb((string)base64_decode(substr((string)$g['signature'], 22))) : null;
    if ($img) $p->image($img, 230);
    else $p->text('(signature held on file)', 10, false, 0.4);
    $p->text('Signed electronically on the course tablet' . (!empty($g['signed_at']) ? ' on ' . $when($g['signed_at']) : '') . '.', 9, false, 0.4);
    $p->gap(6);
    $p->text('This is an electronic copy of the agreement signed at ' . $club . '. Please keep it for your records.', 8.5, false, 0.5);
    $pi = pdf_image_from_dataurl($promo); // the course's promotional banner, full width at the foot of the page
    if ($pi) { $p->gap(14); $p->image($pi, min(MiniPdf::W - 2 * MiniPdf::M, 150.0 * $pi['w'] / $pi['h'])); }
    return $p->output();
}

/* ---------- email settings: the system default (course_id 0) and each course's own choice ---------- */

function mail_key(): string { $c = cfg(); return hash('sha256', (string)($c['secret_key'] ?? '') . ($c['db_name'] ?? '') . ($c['db_pass'] ?? '') . 'bula-mail', true); }

/** SMTP passwords are stored encrypted and never sent back to the browser. */
function secret_enc(string $s): string {
    if (!function_exists('openssl_encrypt')) fail(500, 'This server cannot store passwords securely (PHP openssl is missing)');
    $iv = random_bytes(16);
    return base64_encode($iv . openssl_encrypt($s, 'aes-256-cbc', mail_key(), OPENSSL_RAW_DATA, $iv));
}
function secret_dec(?string $s): string {
    if (!$s || !function_exists('openssl_decrypt')) return '';
    $raw = base64_decode($s, true);
    if ($raw === false || strlen($raw) < 17) return '';
    return (string)openssl_decrypt(substr($raw, 16), 'aes-256-cbc', mail_key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
}

function email_row(int $course): array {
    try { return q('SELECT * FROM email_settings WHERE course_id=?', [$course])->fetch() ?: []; } catch (Throwable $e) { return []; } // table missing until migrate_email.sql has run
}

/** How to send for a course: its own account if it chose one, otherwise the system's; always with the course's own name and reply-to. */
function mail_settings_for(int $course): array {
    $c = cfg(); $sys = email_row(0); $own = $course ? email_row($course) : [];
    $club = $course ? (q('SELECT name, full_name FROM courses WHERE id=?', [$course])->fetch() ?: []) : [];
    $useOwn = $own && !(int)($own['use_system'] ?? 1) && !empty($own['from_email']);
    $base = $useOwn ? $own : $sys;
    $host = preg_replace('/^www\./', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return [
        'enabled' => !$own || (int)($own['enabled'] ?? 1) === 1,
        'source' => $useOwn ? 'own' : 'system',
        'method' => ($base['method'] ?? 'mail') === 'smtp' && !empty($base['smtp_host']) ? 'smtp' : 'mail',
        'from' => (string)(($base['from_email'] ?? '') ?: ($c['mail_from'] ?? ('noreply@' . $host))),
        'from_name' => (string)(($own['from_name'] ?? '') ?: ($club['full_name'] ?? '') ?: ($club['name'] ?? '') ?: ($sys['from_name'] ?? '') ?: ($c['mail_from_name'] ?? 'Guest check-in')),
        'reply_to' => (string)(($own['reply_to'] ?? '') ?: ($sys['reply_to'] ?? '') ?: ($c['mail_reply_to'] ?? '')),
        'smtp' => ['host' => (string)($base['smtp_host'] ?? ''), 'port' => (int)($base['smtp_port'] ?? 587) ?: 587, 'secure' => (string)($base['smtp_secure'] ?? 'tls'), 'user' => (string)($base['smtp_user'] ?? ''), 'pass' => secret_dec($base['smtp_pass'] ?? '')],
    ];
}

/* ---------- building and sending ---------- */

function build_mime(array $s, string $subject, string $text, string $html, string $fileName, string $pdf): array {
    $clean = static fn($v) => trim(preg_replace('/[\r\n]+/', ' ', (string)$v));
    $b1 = '=_m' . bin2hex(random_bytes(8)); $b2 = '=_a' . bin2hex(random_bytes(8));
    $hdr = ['From: =?UTF-8?B?' . base64_encode($clean($s['from_name'])) . '?= <' . $clean($s['from']) . '>'];
    if ($clean($s['reply_to']) !== '') $hdr[] = 'Reply-To: ' . $clean($s['reply_to']);
    $hdr[] = 'MIME-Version: 1.0';
    $hdr[] = "Content-Type: multipart/mixed; boundary=\"$b1\"";
    $part = static fn($type, $body) => "Content-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($body));
    $body = "--$b1\r\nContent-Type: multipart/alternative; boundary=\"$b2\"\r\n\r\n--$b2\r\n" . $part('text/plain', $text) . "--$b2\r\n" . $part('text/html', $html) . "--$b2--\r\n";
    if ($pdf !== '') $body .= "--$b1\r\nContent-Type: application/pdf; name=\"$fileName\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$fileName\"\r\n\r\n" . chunk_split(base64_encode($pdf));
    return [$hdr, $body . "--$b1--\r\n", '=?UTF-8?B?' . base64_encode($subject) . '?='];
}

/** Plain SMTP client: SSL or STARTTLS, AUTH LOGIN. @return array{0:bool,1:string} */
function smtp_send(array $c, string $from, string $to, string $data): array {
    $host = $c['host']; $port = $c['port'] ?: 587; $secure = $c['secure'];
    if (!preg_match('/^[A-Za-z0-9.-]+$/', $host)) return [false, 'The SMTP server name looks wrong'];
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
    if (!$fp) return [false, "Could not connect to $host:$port ($errstr)"];
    stream_set_timeout($fp, 20);
    $read = static function () use ($fp): string { $r = ''; while (($l = fgets($fp, 1024)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $r; };
    $cmd = static function (string $s, array $ok) use ($fp, $read): array { fwrite($fp, $s . "\r\n"); $r = $read(); return [in_array((int)substr($r, 0, 3), $ok, true), trim($r)]; };
    $bye = static function (string $m) use ($fp): array { @fwrite($fp, "QUIT\r\n"); @fclose($fp); return [false, $m]; };
    $g = $read();
    if ((int)substr($g, 0, 3) !== 220) return $bye('The mail server did not answer properly: ' . trim($g));
    $me = preg_replace('/[^A-Za-z0-9.-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
    [$ok, $r] = $cmd("EHLO $me", [250]); if (!$ok) return $bye($r);
    if ($secure === 'tls') {
        [$ok, $r] = $cmd('STARTTLS', [220]); if (!$ok) return $bye($r);
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) return $bye('Could not start a secure (TLS) connection');
        [$ok, $r] = $cmd("EHLO $me", [250]); if (!$ok) return $bye($r);
    }
    if ($c['user'] !== '') {
        [$ok, $r] = $cmd('AUTH LOGIN', [334]); if (!$ok) return $bye($r);
        [$ok, $r] = $cmd(base64_encode($c['user']), [334]); if (!$ok) return $bye($r);
        [$ok, $r] = $cmd(base64_encode($c['pass']), [235]); if (!$ok) return $bye('Sign-in to the mail server failed: ' . $r);
    }
    [$ok, $r] = $cmd("MAIL FROM:<$from>", [250]); if (!$ok) return $bye($r);
    [$ok, $r] = $cmd("RCPT TO:<$to>", [250, 251]); if (!$ok) return $bye($r);
    [$ok, $r] = $cmd('DATA', [354]); if (!$ok) return $bye($r);
    $data = str_replace("\n", "\r\n", preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $data)));
    fwrite($fp, $data . "\r\n.\r\n");
    $r = $read(); $ok = (int)substr($r, 0, 3) === 250;
    @fwrite($fp, "QUIT\r\n"); @fclose($fp);
    return [$ok, $ok ? 'Sent' : trim($r)];
}

/** @return array{0:bool,1:string} */
function mail_send(string $to, string $subject, string $text, string $html, string $fileName, string $pdf, array $s): array {
    [$hdr, $body, $subjEnc] = build_mime($s, $subject, $text, $html, $fileName, $pdf);
    if ($s['method'] === 'smtp') {
        $dom = preg_replace('/^.*@/', '', $s['from']) ?: 'localhost';
        $head = implode("\r\n", $hdr) . "\r\nTo: $to\r\nSubject: $subjEnc\r\nDate: " . date('r') . "\r\nMessage-ID: <" . bin2hex(random_bytes(8)) . "@$dom>";
        return smtp_send($s['smtp'], $s['from'], $to, $head . "\r\n\r\n" . $body);
    }
    $ok = @mail($to, $subjEnc, $body, implode("\r\n", $hdr), '-f' . $s['from']);
    return [$ok, $ok ? 'Sent' : 'The mail server did not accept the message'];
}

/** A short message to check a course's (or the system's) email setup works. @return array{0:bool,1:string} */
function send_test_email(int $course, string $to): array {
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return [false, 'That is not a valid email address'];
    $s = mail_settings_for($course);
    $who = $s['from_name'];
    $text = "This is a test message from the check-in system for $who.\n\nIf you can read this, signed-agreement emails will send from {$s['from']} " . ($s['source'] === 'own' ? '(this course\'s own email account)' : '(the system email account)') . ".\n";
    $html = '<div style="font-family:Arial,sans-serif"><h3>' . htmlspecialchars($who) . '</h3><p>This is a test message from the check-in system.</p><p>If you can read this, signed-agreement emails will send from <b>' . htmlspecialchars($s['from']) . '</b> (' . ($s['source'] === 'own' ? "this course's own email account" : 'the system email account') . ').</p></div>';
    return mail_send($to, 'Test email from ' . $who, $text, $html, '', '', $s);
}

/** Load one signed record and build its PDF (with the course logo). */
function signed_pdf_for(int $cgId, ?int $course = null): ?array {
    $g = q('SELECT cg.*, g.first_name, g.last_name FROM checkin_golfers cg JOIN guests g ON g.id=cg.guest_id WHERE cg.id=?', [$cgId])->fetch();
    if (!$g) return null;
    $ciId = (int)$g['checkin_id'];
    $ci = checkin_rows('c.id=?', [$ciId])[0] ?? null;
    $row = q('SELECT course_id, terms_version FROM checkins WHERE id=?', [$ciId])->fetch();
    if (!$ci || !$row || ($course !== null && (int)$row['course_id'] !== $course)) return null;
    $club = q('SELECT name, full_name, logo, promo FROM courses WHERE id=?', [$row['course_id']])->fetch() ?: [];
    $clubName = ($club['full_name'] ?? '') ?: ($club['name'] ?? 'the course');
    $terms = (string)q('SELECT body FROM terms WHERE version=?', [$row['terms_version']])->fetchColumn();
    return ['pdf' => build_signed_pdf($clubName, $ci, $g, $terms, (int)$row['terms_version'], (string)($club['logo'] ?? ''), (string)($club['promo'] ?? '')), 'ci' => $ci, 'g' => $g, 'club' => $clubName, 'course' => (int)$row['course_id']];
}

/** Build and email one golfer's signed copy using that course's email settings, and log the outcome. @return array{0:bool,1:string} */
function send_signed_copy(int $cgId, string $to): array {
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return [false, 'That is not a valid email address'];
    $d = signed_pdf_for($cgId);
    if (!$d) return [false, 'Signed record not found'];
    ['pdf' => $pdf, 'ci' => $ci, 'g' => $g, 'club' => $clubName] = $d;
    $s = mail_settings_for($d['course']);
    $ciId = (int)$g['checkin_id'];
    $first = (string)$g['first_name']; $date = date('j M Y', strtotime((string)$ci['checked_in_at']));
    $text = "Hi $first,\n\nThank you for visiting $clubName. Attached is a PDF copy of the cart and equipment hire agreement you signed on $date (reference #$ciId).\n\nPlease keep this for your records. If you did not sign this, just reply to let us know.\n\n$clubName\n";
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;color:#1b2a21;max-width:520px"><h2 style="font-family:Georgia,serif;color:#0f3d26;margin:0 0 6px">' . htmlspecialchars($clubName) . '</h2>'
          . '<p>Hi ' . htmlspecialchars($first) . ',</p><p>Thank you for visiting. Attached is a PDF copy of the cart and equipment hire agreement you signed on ' . htmlspecialchars($date) . ' (reference #' . $ciId . ').</p>'
          . '<p>Please keep this for your records. If you did not sign this, just reply to let us know.</p><p style="color:#68756c">' . htmlspecialchars($clubName) . '</p></div>';
    [$ok, $msg] = mail_send($to, 'Your signed agreement: ' . $clubName, $text, $html, 'signed-agreement-' . $ciId . '.pdf', $pdf, $s);
    try { q('INSERT INTO email_log (checkin_id, golfer_id, to_email, status, detail) VALUES (?,?,?,?,?)', [$ciId, $cgId, mb_substr($to, 0, 150), $ok ? 'sent' : 'failed', $ok ? null : mb_substr($msg, 0, 250)]); } catch (Throwable $e) { /* log table not created yet */ }
    return [$ok, $msg];
}
