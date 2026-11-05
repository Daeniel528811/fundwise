<?php
/** Fundwise PDF export. Included by reports/index.php after authentication and queries. */
if (!isset($user_id, $start_date, $end_date, $transactions, $category_breakdown)) {
    http_response_code(403);
    exit('Open this export from Fundwise Reports.');
}

final class FundwisePdf {
    private array $pages = [];
    private string $stream = '';
    private float $y = 0;
    private int $page = 0;
    private const W = 595.28;
    private const H = 841.89;
    private const LEFT = 42;
    private const RIGHT = 553;

    private function safe(string $s): string {
        $s = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $s) ?? '';
        $s = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s) ?: '';
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }
    private function fill(float $x, float $top, float $w, float $h, string $rgb): void {
        [$r,$g,$b] = array_map('floatval', explode(',', $rgb));
        $this->stream .= sprintf('%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f' . "\n", $r,$g,$b,$x,self::H-$top-$h,$w,$h);
    }
    private function text(float $x, float $top, string $s, int $size=10, bool $bold=false, string $rgb='0.16,0.12,0.27'): void {
        [$r,$g,$b] = array_map('floatval',explode(',',$rgb));
        $this->stream .= sprintf('BT /F%d %d Tf %.3f %.3f %.3f rg %.2f %.2f Td (%s) Tj ET' . "\n", $bold?2:1,$size,$r,$g,$b,$x,self::H-$top,$this->safe($s));
    }
    private function shorten(string $s, int $limit): string {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        if (mb_strlen($s, 'UTF-8') <= $limit) return $s;
        return mb_substr($s, 0, $limit-3, 'UTF-8') . '...';
    }
    private function startPage(string $period): void {
        if ($this->page) $this->pages[] = $this->stream;
        $this->page++;
        $this->stream = '';
        $this->fill(0,0,self::W,90,'0.15,0.08,0.30');
        $this->fill(0,90,self::W,4,'0.43,0.16,0.85');
        $this->text(self::LEFT,39,'FUNDWISE',21,true,'1,1,1');
        $this->text(self::LEFT,62,'FINANCIAL REPORT',11,true,'0.88,0.82,1');
        $this->text(self::LEFT,115,$period,10,false,'0.40,0.35,0.49');
        $this->y=137;
    }
    private function ensure(float $height, string $period): void {
        if ($this->y+$height > 775) $this->startPage($period);
    }
    private function heading(string $title, string $period): void {
        $this->ensure(38,$period);
        $this->fill(self::LEFT,$this->y,511,25,'0.94,0.91,1');
        $this->text(self::LEFT+10,$this->y+17,$title,11,true,'0.24,0.11,0.44');
        $this->y+=35;
    }
    public function generate(string $start, string $end, string $currency, float $income, float $expenses, array $breakdown, array $transactions): string {
        $period = 'Period: '.$start.' to '.$end.'     |     Currency: '.$currency;
        $this->startPage($period);
        $this->heading('FINANCIAL SUMMARY',$period);
        $cards=[['TOTAL INCOME',$income],['TOTAL EXPENSES',$expenses],['REMAINING BALANCE',$income-$expenses]];
        foreach ($cards as $i=>$card) {
            $x=self::LEFT+$i*174;
            $this->fill($x,$this->y,163,62,'0.97,0.96,1');
            $this->text($x+9,$this->y+19,$card[0],9,true,'0.42,0.37,0.51');
            $this->text($x+9,$this->y+42,$this->shorten($currency.' '.number_format($card[1],2),22),11,true,'0.24,0.11,0.44');
        }
        $this->y+=81;
        $this->heading('EXPENSE BREAKDOWN',$period);
        if (!$breakdown) {
            $this->text(self::LEFT+10,$this->y+13,'No expenses for this period.',10);
            $this->y+=29;
        } else {
            foreach ($breakdown as $item) {
                $this->ensure(24,$period);
                $this->text(self::LEFT+10,$this->y+13,$this->shorten((string)($item['category_name'] ?? 'Uncategorized'),52),10);
                $this->text(420,$this->y+13,$this->shorten($currency.' '.number_format((float)$item['total_amount'],2),24),10,true);
                $this->y+=23;
            }
            $this->y+=8;
        }
        $this->heading('TRANSACTION HISTORY ('.count($transactions).')',$period);
        $drawHeader=function(): void {
            $this->fill(self::LEFT,$this->y,511,26,'0.24,0.11,0.44');
            foreach ([[50,'DATE'],[130,'DESCRIPTION'],[310,'CATEGORY'],[407,'TYPE'],[467,'AMOUNT']] as [$x,$s]) {
                $this->text($x,$this->y+17,$s,8,true,'1,1,1');
            }
            $this->y+=26;
        };
        $drawHeader();
        if (!$transactions) {
            $this->text(50,$this->y+18,'No transactions for this period.',10);
            $this->y+=30;
        }
        foreach ($transactions as $i=>$t) {
            if ($this->y+28 > 775) {
                $this->startPage($period);
                $this->heading('TRANSACTION HISTORY (CONTINUED)',$period);
                $drawHeader();
            }
            if ($i%2===0) $this->fill(self::LEFT,$this->y,511,25,'0.97,0.96,1');
            $this->text(50,$this->y+16,(string)($t['transaction_date']??''),9);
            $this->text(130,$this->y+16,$this->shorten((string)($t['description']??''),29),9);
            $this->text(310,$this->y+16,$this->shorten((string)($t['category_name']??'Uncategorized'),16),9);
            $this->text(407,$this->y+16,ucfirst((string)($t['transaction_type']??'')),9);
            $this->text(467,$this->y+16,$this->shorten(number_format((float)($t['amount']??0),2),15),9,true);
            $this->y+=25;
        }
        $this->pages[]=$this->stream;
        return $this->build();
    }
    private function build(): string {
        $objects=[];
        $add=function(string $obj) use (&$objects): int { $objects[]=$obj; return count($objects); };
        $catalog=$add('');
        $pagesRoot=$add('');
        $fontRegular=$add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $fontBold=$add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
        $pageIds=[];
        foreach ($this->pages as $i=>$content) {
            $content .= sprintf("BT /F1 8 Tf 0.45 0.41 0.50 rg 42 29 Td (Fundwise  |  Page %d of %d) Tj ET\n",$i+1,count($this->pages));
            $contentId=$add('<< /Length '.strlen($content).' >>' . "\nstream\n" . $content . "endstream");
            $pageIds[]=$add(sprintf('<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> /Contents %d 0 R >>',$pagesRoot,self::W,self::H,$fontRegular,$fontBold,$contentId));
        }
        $objects[$catalog-1]='<< /Type /Catalog /Pages '.$pagesRoot.' 0 R >>';
        $objects[$pagesRoot-1]='<< /Type /Pages /Kids ['.implode(' ',array_map(fn($id)=>$id.' 0 R',$pageIds)).'] /Count '.count($pageIds).' >>';
        $pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets=[0];
        foreach ($objects as $i=>$obj) {
            $offsets[]=strlen($pdf);
            $pdf.=($i+1)." 0 obj\n".$obj."\nendobj\n";
        }
        $xref=strlen($pdf);
        $pdf.="xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";
        for ($i=1;$i<count($offsets);$i++) $pdf.=sprintf("%010d 00000 n \n",$offsets[$i]);
        $pdf.="trailer\n<< /Size ".(count($objects)+1)." /Root $catalog 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $pdf;
    }
}

$pdf=(new FundwisePdf())->generate($start_date,$end_date,$currency_code,(float)$total_income,(float)$total_expenses,$category_breakdown,$transactions);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="fundwise_report_'.$start_date.'_to_'.$end_date.'.pdf"');
header('Content-Length: '.strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
exit;
