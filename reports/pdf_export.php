<?php
/** Self-contained, print-friendly Fundwise PDF export; no Composer/GD required. */
if (!isset($user_id,$start_date,$end_date,$transactions,$category_breakdown)) {
    http_response_code(403);
    exit('Open this export from Fundwise Reports.');
}

final class FundwisePdf {
    private array $pages=[];
    private string $stream='';
    private float $y=0;
    private int $page=0;
    private string $period='';
    private string $currency='PHP';
    private string $generated='';
    private const W=595.28;
    private const H=841.89;
    private const LEFT=44;
    private const RIGHT=551.28;
    private const BOTTOM=767;
    private const NAVY='0.063,0.137,0.243';
    private const BLUE='0.086,0.439,0.878';
    private const CYAN='0.051,0.694,0.761';
    private const MUTED='0.384,0.447,0.529';
    private const BORDER='0.878,0.906,0.937';
    // Core-font width metrics are filled by the authoring script, not at runtime.
    private const REGULAR_WIDTHS=[600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,350,556,350,222,556,333,1000,556,556,333,1000,667,333,1000,350,611,350,350,222,222,333,333,350,556,1000,333,1000,500,333,944,350,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500];
    private const BOLD_WIDTHS=[600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,600,278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,350,556,350,278,556,500,1000,556,556,333,1000,667,333,1000,350,611,350,350,278,278,500,500,350,556,1000,333,1000,556,333,944,350,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556];

    private function encoded(string $value): string {
        $value=preg_replace('/[\x00-\x1F\x7F]/u',' ',$value)??'';
        $encoded=iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$value);
        return $encoded===false?'':$encoded;
    }
    private function escape(string $value):string {
        return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$this->encoded($value));
    }
    private function width(string $value,float $size,bool $bold=false):float {
        $value=$this->encoded($value);$width=0;$metrics=$bold?self::BOLD_WIDTHS:self::REGULAR_WIDTHS;
        for($i=0;$i<strlen($value);$i++)$width+=$metrics[ord($value[$i])]??600;
        return $width*$size/1000;
    }
    private function text(float $x,float $top,string $value,float $size=10,bool $bold=false,string $rgb=self::NAVY):void {
        [$r,$g,$b]=array_map('floatval',explode(',',$rgb));
        $this->stream.=sprintf("BT /F%d %.2f Tf %.3f %.3f %.3f rg %.2f %.2f Td (%s) Tj ET\n",$bold?2:1,$size,$r,$g,$b,$x,self::H-$top,$this->escape($value));
    }
    private function right(float $right,float $top,string $value,float $size=10,bool $bold=false,string $rgb=self::NAVY,float $maxWidth=0):void {
        if($maxWidth>0)$size=$this->fit($value,$maxWidth,$size,$bold);
        $this->text($right-$this->width($value,$size,$bold),$top,$value,$size,$bold,$rgb);
    }
    private function fit(string $value,float $width,float $size,bool $bold=false):float {
        $actual=$this->width($value,$size,$bold);
        return $actual>$width&&$actual>0?$size*$width/$actual:$size;
    }
    private function fill(float $x,float $top,float $width,float $height,string $rgb):void {
        [$r,$g,$b]=array_map('floatval',explode(',',$rgb));
        $this->stream.=sprintf("%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f\n",$r,$g,$b,$x,self::H-$top-$height,$width,$height);
    }
    private function rule(float $top,string $rgb=self::BORDER):void {$this->fill(self::LEFT,$top,self::RIGHT-self::LEFT,.6,$rgb);}
    private function wrap(string $value,float $width,float $size=9,bool $bold=false):array {
        $value=trim(preg_replace('/\s+/u',' ',$value)??'');
        if($value==='')return ['-'];
        $lines=[];$line='';
        foreach(explode(' ',$value) as $word){
            $candidate=$line===''?$word:$line.' '.$word;
            if($this->width($candidate,$size,$bold)<=$width){$line=$candidate;continue;}
            if($line!==''){$lines[]=$line;$line='';}
            if($this->width($word,$size,$bold)<=$width){$line=$word;continue;}
            foreach(preg_split('//u',$word,-1,PREG_SPLIT_NO_EMPTY)?:str_split($word) as $char){
                if($line!==''&&$this->width($line.$char,$size,$bold)>$width){$lines[]=$line;$line='';}
                $line.=$char;
            }
        }
        if($line!=='')$lines[]=$line;
        return $lines?:['-'];
    }
    private function startPage(bool $first=false,string $account=''):void {
        if($this->page)$this->pages[]=$this->stream;
        $this->page++;$this->stream='';
        // Small two-tone brand mark rather than a heavy ink-filled banner.
        $this->fill(self::LEFT,27,3,28,self::BLUE);
        $this->fill(self::LEFT+3,27,3,28,self::CYAN);
        $this->text(self::LEFT+16,44,'Fundwise',21,true);
        $this->text(self::LEFT+111,44,'.',21,true,self::CYAN);
        $this->text(self::LEFT+16,57,'YOUR MONEY, SIMPLIFIED',6.8,true,self::MUTED);
        $this->right(self::RIGHT,36,'GENERATED',6.8,true,self::MUTED);
        $this->right(self::RIGHT,51,$this->generated,8,false,self::MUTED);
        if($first){
            $this->text(self::LEFT,95,'Financial report',25,true);
            $this->text(self::LEFT,116,$this->period,9.5,false,self::MUTED);
            $this->right(self::RIGHT,116,'All amounts in '.$this->currency,8.5,false,self::MUTED,130);
            $this->y=142;
            if($account!==''){
                $lines=$this->wrap('Prepared for '.$account,507,8.5);
                foreach($lines as $line){$this->text(self::LEFT,$this->y,$line,8.5,false,self::MUTED);$this->y+=11;}
                $this->y+=9;
            }
        }else{
            $this->text(self::LEFT,84,'Financial report',13,true);
            $this->right(self::RIGHT,84,$this->period,8,false,self::MUTED,300);
            $this->rule(98);$this->y=119;
        }
    }
    private function ensure(float $height):bool {
        if($this->y+$height<=self::BOTTOM)return false;
        $this->startPage();return true;
    }
    private function heading(string $number,string $title,float $reserve=55):void {
        $this->ensure($reserve);
        $this->text(self::LEFT,$this->y+10,$number,8,true,self::BLUE);
        $this->text(self::LEFT+23,$this->y+11,$title,12,true);
        $this->y+=26;
    }
    private function tableHeader(string $kind):void {
        $this->fill(self::LEFT,$this->y,self::RIGHT-self::LEFT,24,'0.949,0.965,0.984');
        if($kind==='breakdown'){
            $this->text(53,$this->y+16,'CATEGORY',7,true,self::MUTED);
            $this->right(467,$this->y+16,'AMOUNT',7,true,self::MUTED);
            $this->right(542,$this->y+16,'SHARE',7,true,self::MUTED);
        }else{
            foreach([[52,'DATE'],[122,'DESCRIPTION'],[292,'CATEGORY'],[397,'TYPE']] as [$x,$label])$this->text($x,$this->y+16,$label,7,true,self::MUTED);
            $this->right(543,$this->y+16,'AMOUNT',7,true,self::MUTED);
        }
        $this->y+=24;
    }
    public function generate(string $start,string $end,string $currency,float $income,float $expenses,array $breakdown,array $transactions,string $account=''):string {
        $this->currency=preg_match('/^[A-Z]{3}$/D',$currency)?$currency:'PHP';
        $this->period=date('M j, Y',strtotime($start)).' - '.date('M j, Y',strtotime($end));
        $this->generated=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('M j, Y  g:i A').' PHT';
        $this->startPage(true,$account);
        $this->heading('01','Period overview',130);
        $cardWidth=(self::RIGHT-self::LEFT-24)/3;
        $cards=[['TOTAL INCOME',$income,'Recorded income','0.055,0.525,0.412'],['TOTAL EXPENSES',$expenses,'Recorded expenses','0.780,0.341,0.420'],['NET CASH FLOW',$income-$expenses,'Income minus expenses',self::BLUE]];
        foreach($cards as $i=>[$label,$value,$hint,$accent]){
            $x=self::LEFT+$i*($cardWidth+12);
            $this->fill($x,$this->y,$cardWidth,78,$i===2?'0.918,0.953,0.996':'0.965,0.976,0.988');
            $this->fill($x,$this->y,$cardWidth,2,$accent);
            $this->text($x+12,$this->y+21,$label,7.2,true,self::MUTED);
            $amount=$this->currency.' '.number_format($value,2);
            $size=$this->fit($amount,$cardWidth-24,15,true);
            $this->text($x+12,$this->y+47,$amount,$size,true,$i===2?self::BLUE:self::NAVY);
            $this->text($x+12,$this->y+65,$hint,7.5,false,self::MUTED);
        }
        $this->y+=94;
        $this->text(self::LEFT,$this->y,'Net cash flow covers this report period. Savings contributions are tracked separately.',8,false,self::MUTED);
        $this->y+=27;
        $firstCategory=$breakdown[0]??null;
        $firstCategoryHeight=$firstCategory?max(29,count($this->wrap((string)($firstCategory['category_name']??'Uncategorized'),300,9))*12+15):39;
        $this->heading('02','Spending by category',$firstCategoryHeight+50);
        $this->tableHeader('breakdown');
        if(!$breakdown){$this->text(53,$this->y+20,'No recorded expenses for this period.',9,false,self::MUTED);$this->y+=39;}
        foreach($breakdown as $item){
            $lines=$this->wrap((string)($item['category_name']??'Uncategorized'),300,9);
            $height=max(29,count($lines)*12+15);
            if($this->ensure($height)){$this->heading('02','Spending by category / continued',$height+50);$this->tableHeader('breakdown');}
            foreach($lines as $line=>$value)$this->text(53,$this->y+18+$line*12,$value,9);
            $amount=(float)($item['total_amount']??0);
            $this->right(467,$this->y+18,number_format($amount,2),9,true,self::NAVY,105);
            $this->right(542,$this->y+18,$expenses>0?number_format($amount/$expenses*100,1).'%':'-',8.5,false,self::MUTED,60);
            $this->y+=$height;$this->rule($this->y);
        }
        $this->y+=23;
        $firstTransaction=$transactions[0]??null;
        $firstRowHeight=$firstTransaction?max(32,max(count($this->wrap((string)($firstTransaction['description']??''),154,9)),count($this->wrap((string)($firstTransaction['category_name']??'Uncategorized'),89,8.5)))*12+17):40;
        $this->heading('03','Transaction details',$firstRowHeight+50);
        $this->right(self::RIGHT,$this->y-15,count($transactions).' record'.(count($transactions)===1?'':'s'),8,false,self::MUTED);
        $this->tableHeader('transactions');
        if(!$transactions){$this->text(53,$this->y+22,'No recorded transactions for this period.',9,false,self::MUTED);$this->y+=40;}
        foreach($transactions as $i=>$transaction){
            $description=$this->wrap((string)($transaction['description']??''),154,9);
            $category=$this->wrap((string)($transaction['category_name']??'Uncategorized'),89,8.5);
            $height=max(32,max(count($description),count($category))*12+17);
            if($this->ensure($height)){$this->heading('03','Transaction details / continued',$height+50);$this->tableHeader('transactions');}
            if($i%2===0)$this->fill(self::LEFT,$this->y,self::RIGHT-self::LEFT,$height,'0.980,0.986,0.994');
            $this->text(52,$this->y+19,(string)($transaction['transaction_date']??''),8,false,self::MUTED);
            foreach($description as $line=>$value)$this->text(122,$this->y+19+$line*12,$value,9);
            foreach($category as $line=>$value)$this->text(292,$this->y+19+$line*12,$value,8.5,false,self::MUTED);
            $isIncome=($transaction['transaction_type']??'')==='income';
            $this->text(397,$this->y+19,$isIncome?'Income':'Expense',8,false,$isIncome?'0.055,0.478,0.376':'0.725,0.294,0.357');
            $this->right(543,$this->y+19,number_format((float)($transaction['amount']??0),2),9,true,self::NAVY,90);
            $this->y+=$height;$this->rule($this->y);
        }
        $this->pages[]=$this->stream;
        return $this->build();
    }
    private function build():string {
        $objects=[];$add=static function(string $object)use(&$objects):int{$objects[]=$object;return count($objects);};
        $catalog=$add('');$root=$add('');
        $regular=$add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $bold=$add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
        $pageIds=[];$total=count($this->pages);
        foreach($this->pages as $i=>$content){
            $this->stream=$content;
            $this->rule(790);
            $this->text(self::LEFT,808,'Fundwise',9,true,self::NAVY);
            $this->text(self::LEFT+55,808,'Personal financial report',7.5,false,self::MUTED);
            $this->right(self::RIGHT,808,'Page '.($i+1).' of '.$total,8,false,self::MUTED);
            $content=$this->stream;
            $contentId=$add('<< /Length '.strlen($content)." >>\nstream\n".$content.'endstream');
            $pageIds[]=$add(sprintf('<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> /Contents %d 0 R >>',$root,self::W,self::H,$regular,$bold,$contentId));
        }
        $objects[$catalog-1]='<< /Type /Catalog /Pages '.$root.' 0 R >>';
        $objects[$root-1]='<< /Type /Pages /Kids ['.implode(' ',array_map(static fn($id)=>$id.' 0 R',$pageIds)).'] /Count '.$total.' >>';
        $info=$add('<< /Title (Fundwise Financial Report) /Author (Fundwise) /Creator (Fundwise PHP exporter) >>');
        $pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";$offsets=[0];
        foreach($objects as $i=>$object){$offsets[]=strlen($pdf);$pdf.=($i+1)." 0 obj\n".$object."\nendobj\n";}
        $xref=strlen($pdf);$pdf.='xref'."\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";
        for($i=1;$i<count($offsets);$i++)$pdf.=sprintf("%010d 00000 n \n",$offsets[$i]);
        return $pdf.'trailer'."\n<< /Size ".(count($objects)+1)." /Root $catalog 0 R /Info $info 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }
}

$account=is_string($_SESSION['full_name']??null)?$_SESSION['full_name']:'';
$pdf=(new FundwisePdf())->generate($start_date,$end_date,$currency_code,(float)$total_income,(float)$total_expenses,$category_breakdown,$transactions,$account);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="fundwise_report_'.$start_date.'_to_'.$end_date.'.pdf"');
header('Content-Length: '.strlen($pdf));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
echo $pdf;
exit;
