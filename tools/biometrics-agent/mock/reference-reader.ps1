$ip='192.168.1.201'; $port=4370; $key=0; $out=[Environment]::GetFolderPath('Desktop')
function CkSum([byte[]]$p){ $k=0; for($i=0;$i+1 -lt $p.Length;$i+=2){ $k+=[BitConverter]::ToUInt16($p,$i); if($k -gt 65535){$k-=65535} }; if($p.Length % 2){$k+=$p[$p.Length-1]}; while($k -gt 65535){$k-=65535}; $k=-bnot $k; while($k -lt 0){$k+=65535}; return $k }
function Pkt([int]$cmd,[byte[]]$data){ if($null -eq $data){$data=New-Object byte[] 0}; $b=New-Object byte[] (8+$data.Length); [BitConverter]::GetBytes([uint16]$cmd).CopyTo($b,0); [BitConverter]::GetBytes([uint16]$script:sess).CopyTo($b,4); [BitConverter]::GetBytes([uint16]$script:rep).CopyTo($b,6); $data.CopyTo($b,8); $ck=CkSum $b; $script:rep++; if($script:rep -ge 65535){$script:rep-=65535}; [BitConverter]::GetBytes([uint16]$ck).CopyTo($b,2); [BitConverter]::GetBytes([uint16]$script:rep).CopyTo($b,6); $t=New-Object byte[] 8; [BitConverter]::GetBytes([uint16]0x5050).CopyTo($t,0); [BitConverter]::GetBytes([uint16]0x7D82).CopyTo($t,2); [BitConverter]::GetBytes([uint32]$b.Length).CopyTo($t,4); return ,($t+$b) }
function ReadN([int]$n){ $buf=New-Object byte[] $n; $got=0; while($got -lt $n){ $r=$script:st.Read($buf,$got,$n-$got); if($r -le 0){throw 'closed'}; $got+=$r }; return ,$buf }
function Frame(){ $h=ReadN 8; $len=[BitConverter]::ToUInt32($h,4); $r=ReadN $len; $script:rep=[BitConverter]::ToUInt16($r,6); return ,$r }
function Send([int]$cmd,[byte[]]$data){ $p=Pkt $cmd $data; $script:st.Write($p,0,$p.Length) }
function Cmd([int]$cmd,[byte[]]$data){ Send $cmd $data; return ,(Frame) }
function Code([byte[]]$r){ [BitConverter]::ToUInt16($r,0) }
function Body([byte[]]$r){ if($r.Length -le 8){ return ,(New-Object byte[] 0) }; $b=New-Object byte[] ($r.Length-8); [Array]::Copy($r,8,$b,0,$b.Length); return ,$b }
function CommKey([int64]$key,[int64]$sid){ $k=[int64]0; for($i=0;$i -lt 32;$i++){ if($key -band ([int64]1 -shl $i)){ $k=($k -shl 1) -bor 1 } else { $k=$k -shl 1 } }; $k+=$sid; $b=[BitConverter]::GetBytes([uint32]($k -band 0xFFFFFFFF)); $x=[byte[]]@(($b[0] -bxor 0x5A),($b[1] -bxor 0x4B),($b[2] -bxor 0x53),($b[3] -bxor 0x4F)); $t=50; return ,([byte[]]@(($x[2] -bxor $t),($x[3] -bxor $t),$t,($x[1] -bxor $t))) }
function ChunkData([byte[]]$r){
  $c=Code $r
  if($c -eq 1501){ return ,(Body $r) }
  if($c -eq 1500){ $size=[BitConverter]::ToUInt32($r,8); $ms=New-Object IO.MemoryStream
    while($ms.Length -lt $size){ $f=Frame; if((Code $f) -ne 1501){ throw "Unexpected code $(Code $f) while reading data" }; $b=Body $f; $ms.Write($b,0,$b.Length) }
    $f=Frame; return ,$ms.ToArray() }
  throw "Read failed, code $c" }
function ReadBuffer([int]$command,[int]$fct){
  $req=New-Object byte[] 11; $req[0]=1; [BitConverter]::GetBytes([int16]$command).CopyTo($req,1); [BitConverter]::GetBytes([int]$fct).CopyTo($req,3)
  $r=Cmd 1503 $req
  if((Code $r) -eq 1501){ return ,(Body $r) }
  if((Code $r) -ne 2000){ throw "Prepare buffer failed, code $(Code $r)" }
  $size=[BitConverter]::ToUInt32($r,9); $ms=New-Object IO.MemoryStream; $start=0; $max=0xFFC0
  while($start -lt $size){ $n=[Math]::Min($max,$size-$start); $q=New-Object byte[] 8; [BitConverter]::GetBytes([int]$start).CopyTo($q,0); [BitConverter]::GetBytes([int]$n).CopyTo($q,4)
    $d=ChunkData (Cmd 1504 $q); $ms.Write($d,0,$d.Length); $start+=$n; Write-Host -NoNewline "." }
  [void](Cmd 1502 $null); Write-Host ""; return ,$ms.ToArray() }
function Skip4([byte[]]$b){ $x=New-Object byte[] ([Math]::Max(0,$b.Length-4)); [Array]::Copy($b,4,$x,0,$x.Length); return ,$x }
function Str([byte[]]$b,[int]$o,[int]$n){ $s=[Text.Encoding]::ASCII.GetString($b,$o,$n); $i=$s.IndexOf([char]0); if($i -ge 0){$s=$s.Substring(0,$i)}; return $s.Trim() }
function ZTime([byte[]]$b,[int]$o){ $t=[int64][BitConverter]::ToUInt32($b,$o); $sec=$t%60;$t=[Math]::Floor($t/60); $min=$t%60;$t=[Math]::Floor($t/60); $hr=$t%24;$t=[Math]::Floor($t/24); $day=$t%31+1;$t=[Math]::Floor($t/31); $mon=$t%12+1;$t=[Math]::Floor($t/12); return ('{0:0000}-{1:00}-{2:00} {3:00}:{4:00}:{5:00}' -f ($t+2000),$mon,$day,$hr,$min,$sec) }

$script:sess=0; $script:rep=65534
$c=New-Object Net.Sockets.TcpClient; $c.ReceiveTimeout=20000; $c.Connect($ip,$port); $script:st=$c.GetStream()
try {
  $r=Cmd 1000 $null; $code=Code $r; $script:sess=[BitConverter]::ToUInt16($r,4)
  if($code -eq 2005){ $r=Cmd 1102 (CommKey $key $script:sess); $code=Code $r }
  if($code -ne 2000){ "Connect: hindi tinanggap, code $code"; return }
  'Connect: OK'
  $r=Cmd 50 $null; $users=[BitConverter]::ToInt32($r,8+16); $recs=[BitConverter]::ToInt32($r,8+32)
  "Users: $users, Records: $recs"

  $u=ReadBuffer 9 5; $tot=[BitConverter]::ToUInt32($u,0); $ud=Skip4 $u
  $usz= if($users -gt 0){ [int]($tot/$users) } else { 72 }
  $map=@{}; $urows=@()
  for($o=0; $o+$usz -le $ud.Length; $o+=$usz){
    $uid=[BitConverter]::ToUInt16($ud,$o); $priv=$ud[$o+2]
    if($usz -eq 28){ $name=Str $ud ($o+8) 8; $uidstr=[string][BitConverter]::ToUInt32($ud,$o+24) } else { $name=Str $ud ($o+11) 24; $uidstr=Str $ud ($o+48) 24 }
    $map[[int]$uid]=$uidstr; $urows+=[pscustomobject]@{ uid=$uid; user_id=$uidstr; name=$name; admin=($priv -ne 0) } }
  $uf=Join-Path $out ('biometrics-users-{0:yyyyMMdd-HHmm}.csv' -f (Get-Date)); $urows | Export-Csv $uf -NoTypeInformation -Encoding UTF8
  "Users saved: $($urows.Count) -> $uf  (user record size $usz)"

  'Downloading attendance (ilang minuto)...'
  $a=ReadBuffer 13 0; $tot=[BitConverter]::ToUInt32($a,0); $ad=Skip4 $a
  $rsz= if($recs -gt 0){ [int]($tot/$recs) } else { 40 }
  $af=Join-Path $out ('biometrics-attendance-{0:yyyyMMdd-HHmm}.csv' -f (Get-Date))
  $sw=New-Object IO.StreamWriter($af,$false,[Text.Encoding]::UTF8); $sw.WriteLine('user_id,time,status,punch'); $n=0; $first=$null; $last=$null
  for($o=0; $o+$rsz -le $ad.Length; $o+=$rsz){
    if($rsz -eq 8){ $uidv=$map[[int][BitConverter]::ToUInt16($ad,$o)]; $stat=$ad[$o+2]; $tm=ZTime $ad ($o+3); $pu=$ad[$o+7] }
    elseif($rsz -eq 16){ $uidv=[string][BitConverter]::ToUInt32($ad,$o); $tm=ZTime $ad ($o+4); $stat=$ad[$o+8]; $pu=$ad[$o+9] }
    else { $uidv=Str $ad ($o+2) 24; $stat=$ad[$o+26]; $tm=ZTime $ad ($o+27); $pu=$ad[$o+31] }
    $sw.WriteLine("$uidv,$tm,$stat,$pu"); $n++; if(-not $first -or $tm -lt $first){$first=$tm}; if(-not $last -or $tm -gt $last){$last=$tm} }
  $sw.Close()
  "Attendance saved: $n records ($first to $last) -> $af  (record size $rsz)"
} finally { try { [void](Cmd 1001 $null) } catch {}; $c.Close() }

