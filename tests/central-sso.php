<?php
/** Loopback HTTP tests using real app bootstraps/authentication and fixture SQL/connector. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
$options = getopt('', ['connector-root:']);
$connectorRoot = $options['connector-root'] ?? null;
$fixture = sys_get_temp_dir() . '/rationalization-sso-test-' . bin2hex(random_bytes(6));
$app = $fixture . '/htdocs/rationalization-system';
$server = null;
$checks = 0;
$failed = false;
function check(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    ++$checks;
}
function put(string $path, string $body): void {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    file_put_contents($path, $body);
}
function removeFixture(string $path): void {
    if (!is_dir($path)) return;
    foreach (new FilesystemIterator($path) as $item) {
        if ($item->isDir() && !$item->isLink()) removeFixture($item->getPathname()); else unlink($item->getPathname());
    }
    rmdir($path);
}
final class FixtureBrowser {
    public array $cookies = [];
    public function __construct(private string $origin) {}
    public function request(string $path, ?array $data = null, bool $https = true): array {
        if (!str_starts_with($path, '/')) $path = '/rationalization-system/' . $path;
        $headers = ['Connection: close'];
        if ($https) $headers[] = 'X-Fixture-HTTPS: on';
        if ($this->cookies) $headers[] = 'Cookie: ' . implode('; ', array_map(static fn($k, $v) => $k . '=' . $v, array_keys($this->cookies), $this->cookies));
        if ($data !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $context = stream_context_create(['http' => ['method' => $data === null ? 'GET' : 'POST',
            'header' => implode("\r\n", $headers), 'content' => $data === null ? '' : http_build_query($data),
            'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]);
        $body = @file_get_contents($this->origin . $path, false, $context);
        if ($body === false) throw new RuntimeException('Fixture HTTP server unavailable: ' . $path);
        preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
        $response = ['status' => (int)$match[1], 'body' => $body, 'location' => '', 'headers' => $http_response_header];
        foreach ($http_response_header as $header) {
            if (stripos($header, 'Location: ') === 0) $response['location'] = substr($header, 10);
            if (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i', $header, $cookie)) {
                if ($cookie[2] === '' || $cookie[2] === 'deleted') unset($this->cookies[$cookie[1]]); else $this->cookies[$cookie[1]] = $cookie[2];
            }
        }
        return $response;
    }
}

try {
    foreach (['includes/bootstrap.php','includes/central-auth.php','includes/header.php','includes/footer.php','includes/master-data.php',
        'central-sso.php','central-session.php','login.php','logout.php','ModelData.php','production.php','users.php','workspace.php'] as $file) {
        put($app.'/'.$file, file_get_contents($root.'/'.$file));
    }
    // Only the fixture copy's SQL connection is replaced. Authentication,
    // process scopes, CSRF, CSV readers, navigation and callback stay real.
    $bootstrap=preg_replace('/function db\(\): PDO \{.*?\n\}/s','function db(): PDO { return new FixturePdo(); }',file_get_contents($app.'/includes/bootstrap.php'),1,$replaced);
    check($replaced===1,'Fixture replaced only the database connection.');put($app.'/includes/bootstrap.php',$bootstrap);
    foreach(['style.css','csv.css','admin.css','layout-fix.css'] as $asset)put($app.'/assets/'.$asset,'/* fixture */');
    put($app.'/config/config.php',"<?php return ['app_name'=>'Fixture','timezone'=>'UTC','allowed_csv_roots'=>[],'synology_private_file'=>__DIR__.'/missing-private.php'];");
    put($app.'/config/master-data.php', <<<'PHP'
<?php
$rules=[];foreach(array_keys(PROCESS_LIST) as $key)$rules[$key]=[['folder'=>'csv','pattern'=>'master.csv']];
return ['root'=>getenv('RAT_SSO_FIXTURE'),'processes'=>$rules];
PHP);
    put($fixture.'/csv/master.csv',"ModelName,Branch,Barcode\nFixture,1,ABC\n");mkdir($fixture.'/sessions',0700,true);
    put($fixture.'/bridge/runtime.php',file_get_contents(__DIR__.'/fixtures/central-runtime.php'));
    if(is_string($connectorRoot)){
        $functions=rtrim($connectorRoot,'/\\').'/functions.php';if(!is_file($functions))throw new RuntimeException('Connector functions.php not found.');
        put($fixture.'/bridge/functions.php',file_get_contents($functions));put($fixture.'/bridge/curl.php',file_get_contents(__DIR__.'/fixtures/central-curl.php'));
        put($fixture.'/bridge/runtime.php', <<<'PHP'
<?php
namespace NbcCentral;
require_once __DIR__.'/functions.php';require_once __DIR__.'/curl.php';
$GLOBALS['nbc_config']=bridgeConfiguration(['issuer'=>'https://central.invalid:4883/nbc-central','htdocs'=>dirname(__DIR__).'/htdocs',
    'applications'=>['rationalization'=>['folder'=>'rationalization-system','cookie_path'=>'/rationalization-system','key'=>str_repeat('c',64),
    'callback'=>'https://'.$_SERVER['HTTP_HOST'].'/rationalization-system/central-sso.php']]]);
PHP);
    }
    put($fixture.'/central/app/runtime.php',file_get_contents(__DIR__.'/fixtures/central-runtime.php'). <<<'PHP'

function consumeTicket(string $ticket,string $app,string $binding):array{
    $state=fixtureState();
    if($app!=='rationalization'||$binding!=='fixture-binding'||$ticket!=='valid-ticket'||!empty($state['ticket_used']))throw new \RuntimeException('Invalid local ticket.');
    $state['ticket_used']=true;file_put_contents(getenv('RAT_SSO_FIXTURE').'/state.json',json_encode($state));
    return ['user_id'=>7,'local_username'=>$state['username'],'session_hash'=>'fixture-session-hash'];
}
PHP);
    put($fixture.'/central/integrations/session.php','<?php');
    $state=['role'=>'admin','username'=>'0000','account'=>true,'grant'=>true,'outage'=>false,'scopes'=>'kitting'];
    $save=static function()use(&$state,$fixture):void{file_put_contents($fixture.'/state.json',json_encode($state));};$save();
    put($fixture.'/database.php', <<<'PHP'
<?php
class FixturePdo extends PDO{
    public function __construct(){}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new FixtureStatement($query);}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false{$s=$this->prepare($query);$s->execute();return $s;}
}
class FixtureStatement extends PDOStatement{
    private array $params=[];
    public function __construct(private string $sql){}
    public function execute(?array $params=null):bool{
        $this->params=$params??[];
        if(str_starts_with($this->sql,'INSERT INTO dbo.change_history'))file_put_contents(getenv('RAT_SSO_FIXTURE').'/audit.jsonl',json_encode($this->params)."\n",FILE_APPEND);
        elseif(!str_starts_with($this->sql,'SELECT ')||!str_contains($this->sql,'dbo.users'))throw new RuntimeException('Unexpected fixture SQL.');
        return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{
        $s=json_decode(file_get_contents(getenv('RAT_SSO_FIXTURE').'/state.json'),true);
        if(str_contains($this->sql,'WHERE username')&&($this->params[0]??null)!==$s['username'])return false;
        if(str_contains($this->sql,'is_active = 1')&&!$s['account'])return false;
        return ['id'=>42,'username'=>$s['username'],'full_name'=>'Fixture Staff','role'=>$s['role'],'user_process'=>$s['scopes'],
            'is_active'=>$s['account']?1:0,'password_hash'=>password_hash('fixture-password',PASSWORD_DEFAULT)];
    }
    public function fetchColumn(int $column=0):mixed{return 1;}
}
PHP);
    put($app.'/dashboard.php', <<<'PHP'
<?php
require __DIR__.'/includes/bootstrap.php';require_login();
echo json_encode(['name'=>session_name(),'state'=>$_SESSION,'logout'=>ratUrl('logout.php'),'asset'=>ratUrl('assets/style.css'),
    'allowed'=>array_keys(accessible_processes()),'csrf'=>$_SESSION['csrf']]);
PHP);
    put($fixture.'/router.php', <<<'PHP'
<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$file=__DIR__.'/htdocs'.$path;if(!is_file($file)){http_response_code(404);exit;}
$_SERVER['SCRIPT_FILENAME']=$file;$_SERVER['SCRIPT_NAME']=$_SERVER['PHP_SELF']=$path;
if(($_SERVER['HTTP_X_FIXTURE_HTTPS']??'')==='on')$_SERVER['HTTPS']='on';
$state=json_decode(file_get_contents(__DIR__.'/state.json'),true);
if(!empty($state['local'])){putenv('RAT_REMOTE_ROOT=');putenv('RAT_CENTRAL_ROOT='.__DIR__.'/central');}
require __DIR__.'/database.php';require __DIR__.'/htdocs/rationalization-system/central-session.php';
chdir(dirname($file));require $file;
PHP);
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('Cannot reserve fixture port.');
    $address=stream_socket_get_name($socket,false);fclose($socket);
    $env=getenv();$env['RAT_REMOTE_ROOT']=$fixture.'/bridge';$env['RAT_CENTRAL_ROOT']=$fixture.'/missing-central';$env['RAT_SSO_FIXTURE']=$fixture;$env['HTTP_PROXY']=$env['http_proxy']='';
    $server=proc_open([PHP_BINARY,'-d','session.save_path='.$fixture.'/sessions','-d','session.gc_probability=0','-d','display_errors=0','-S',$address,$fixture.'/router.php'],
        [0=>['pipe','r'],1=>['file',$fixture.'/server.log','a'],2=>['file',$fixture.'/server.log','a']],$pipes,$fixture,$env,['bypass_shell'=>true,'create_no_window'=>true]);
    if(!is_resource($server))throw new RuntimeException('Cannot start fixture server.');fclose($pipes[0]);
    $browser=new FixtureBrowser('http://'.$address);$base='/rationalization-system/';
    for($i=0;$i<60;++$i){try{$browser->request($base.'login.php');break;}catch(RuntimeException){usleep(50000);}}
    $launch=static function(FixtureBrowser $client)use($base,$fixture):array{
        $start=$client->request($base.'central-sso.php');check($start['status']===303,'Central GET begins authorization.');
        parse_str((string)parse_url($start['location'],PHP_URL_QUERY),$query);
        check($query['client_id']==='rationalization'&&$query['code_challenge_method']==='S256','Correct client and PKCE.');
        $issued=json_decode(file_get_contents($fixture.'/state.json'),true);$issued['expected_challenge']=$query['code_challenge'];file_put_contents($fixture.'/state.json',json_encode($issued));
        $callback=$base.'central-sso.php?'.http_build_query(['state'=>$query['state'],'code'=>str_repeat('b',64),'iss'=>'https://central.invalid:4883/nbc-central']);
        return [$client->request($callback),$callback];
    };
    $nativeFile=static fn(FixtureBrowser $client):string=>$fixture.'/sessions/sess_'.$client->cookies['RATIONALIZATIONSESSID'];
    $login=static function(FixtureBrowser $client,string $password='fixture-password')use($base):array{
        $form=$client->request($base.'login.php');preg_match('/name="csrf" value="([^"]+)"/',$form['body'],$match);
        return $client->request($base.'login.php',['csrf'=>$match[1]??'','username'=>'0000','password'=>$password]);
    };
    $before=file_get_contents($nativeFile($browser));$nativeId=$browser->cookies['RATIONALIZATIONSESSID'];[$handoff]=$launch($browser);
    check($handoff['status']===303&&str_contains($handoff['location'],'process=cut')&&str_contains($handoff['location'],'rat_auth=central'),'Admin landing.');
    check($browser->cookies['RATIONALIZATIONSESSID']===$nativeId&&file_get_contents($nativeFile($browser))===$before,'SSO preserves native cookie and session bytes.');
    check($browser->cookies['RAT_STAFF_SSO']!==$nativeId,'Independent Central session ID.');
    $central=json_decode($browser->request($base.'dashboard.php?rat_auth=central')['body'],true);
    check($central['name']==='RAT_STAFF_SSO'&&$central['state']['user']['username']==='0000','Leading-zero username preserved.');
    check(count($central['allowed'])===10&&$central['asset']==='assets/style.css','Admin retains all processes; assets unchanged.');
    $model=$browser->request($handoff['location']);check($model['status']===200&&str_contains($model['body'],'Fixture'),'Real ModelData reads fixture CSV.');
    check(str_contains($model['body'],'href="logout.php?rat_auth=central"')&&str_contains($model['body'],'name="rat_auth" value="central"'),'Actual navigation and GET filter retain Central context.');
    check($browser->request($base.'ModelData.php?process=cut&check_source=1&rat_auth=central')['status']===200,'Actual polling endpoint accepts SSO.');
    $workspace=$browser->request($base.'workspace.php?view=csv&process=cut&rat_auth=central');
    check($workspace['status']===200&&str_contains($workspace['body'],'rat_auth=central')&&str_contains($workspace['body'],'embed=1'),'Workspace iframe retains Central context.');
    check($browser->request($base.'login.php',['csrf'=>'wrong','username'=>'0000','password'=>'fixture-password'])['status']===419,'Native CSRF enforced.');
    check(str_contains($login($browser,'wrong')['body'],'Invalid username or password'),'Native password enforced.');check($login($browser)['status']===302,'Native login coexists with SSO.');
    $native=json_decode($browser->request($base.'dashboard.php?rat_auth=native')['body'],true);check($native['name']==='RATIONALIZATIONSESSID','Native session name unchanged.');
    $loggedIn=file_get_contents($nativeFile($browser));$logout=$browser->request($central['logout']);
    check($logout['status']===303&&$logout['location']==='https://central.invalid:4883/nbc-central/index.php','Central logout returns to trusted portal.');
    check(file_get_contents($nativeFile($browser))===$loggedIn,'Central logout preserves native login.');
    [$handoff]=$launch($browser);check($browser->request($native['logout'])['location']==='login.php','Native logout retains destination.');
    check($browser->request($base.'dashboard.php?rat_auth=central')['status']===200,'Native logout preserves Central session.');
    $state['role']='user';$state['scopes']='kitting';$save();
    check($browser->request($base.'ModelData.php?process=cut&rat_auth=central')['status']===403,'Role downgrade updates an existing SSO session.');
    check($browser->request($base.'ModelData.php?process=kitting&rat_auth=central')['status']===200,'New process assignment is read without signing in again.');
    $state['scopes']='finished';$save();
    check($browser->request($base.'ModelData.php?process=kitting&rat_auth=central')['status']===403,'Removed process permission is revoked immediately.');
    check($browser->request($base.'ModelData.php?process=finished&rat_auth=central')['status']===200,'Updated process permission takes effect immediately.');
    foreach(['kitting','subassy','kitting;contact|mrsw','finished',''] as $scopes){
        $state['role']='user';$state['scopes']=$scopes;$save();[$r]=$launch($browser);
        if($scopes===''){check($r['status']===403,'No assigned process cannot enter SSO.');continue;}
        $first=$scopes==='subassy'?'saki':explode(';',$scopes)[0];check($r['status']===303&&str_contains($r['location'],'process='.$first),'User lands on assigned process.');
        $account=json_decode($browser->request($base.'dashboard.php?rat_auth=central')['body'],true);
        check($account['state']['user']['role']==='user'&&$account['state']['user']['user_process']===$scopes,'Native role and process assignments mapped.');
        if($scopes==='subassy')check($account['allowed']===['saki','ato'],'Legacy subassy grants Saki and Ato only.');
        check($browser->request($base.'users.php?rat_auth=central')['status']===403,'User cannot enter actual user management.');
        check($browser->request($base.'ModelData.php?process=cut&rat_auth=central')['status']===403,'Real ModelData process restriction.');
        check($browser->request($base.'production.php?process=cut&rat_auth=central')['status']===403,'Real production denies process before SQL or writes.');
        check($browser->request($r['location'])['status']===200,'Assigned process loads without loop.');
        check($browser->request($r['location'],['csrf'=>'wrong'])['status']===419,'SSO mutation requires native CSRF.');
    }
    $state['role']='admin';$state['scopes']='';$save();[$r,$used]=$launch($browser);$other=new FixtureBrowser('http://'.$address);
    check($other->request($used)['status']===403,'Browser-bound callback.');check($browser->request($used)['status']===403,'State replay rejected.');
    [$r]=$launch($browser);$state['grant']=false;$save();check($browser->request($base.'dashboard.php?rat_auth=central')['status']===403,'Revoked grant rejected.');
    $state['grant']=true;$save();[$r]=$launch($browser);$state['account']=false;$save();
    check($browser->request($base.'dashboard.php?rat_auth=central')['status']===403,'Inactive mapping rejected.');check(str_contains($login($other)['body'],'Invalid username or password'),'Inactive native account rejected.');
    $state['account']=true;$save();[$r]=$launch($browser);$state['outage']=true;$save();
    check($browser->request($central['logout'])['location']==='https://central.invalid:4883/nbc-central/index.php','Central logout works during API outage.');
    check($login($browser)['status']===302,'Native login works during Central outage.');check($browser->request($base.'dashboard.php?rat_auth=native')['status']===200,'Native protected access works during outage.');
    $state['outage']=false;$save();[$r]=$launch($browser);$state['outage']=true;$save();
    $failure=$browser->request($base.'dashboard.php?rat_auth=central');
    check($failure['status']===503,'Timeout is bounded without redirect loop.');
    check(str_contains($failure['body'],'href="/rationalization-system/login.php"'),'Failure offers the actual native login path.');
    check($other->request($base.'central-sso.php?code='.str_repeat('b',64),null,false)['status']===403,'HTTP code response rejected.');
    check($other->request($base.'central-sso.php',null,false)['status']===303,'Initial credential-free HTTP launch upgrades HTTPS.');
    check($other->request($base.'central-sso.php',['ticket'=>'wrong'])['status']===405,'Remote callback rejects POST ticket.');
    $state['outage']=false;$save();[$r]=$launch($browser);put($app.'/.nbc-central-disabled','disabled');
    check($browser->request($base.'dashboard.php?rat_auth=central')['status']===503,'Disabled marker blocks Central session.');
    check($browser->request($base.'dashboard.php?rat_auth=native')['status']===200,'Disabled marker preserves native access.');unlink($app.'/.nbc-central-disabled');
    $state['local']=true;$state['role']='user';$state['scopes']='ato';$save();$browser->cookies['NBC_SSO_BIND']='fixture-binding';
    $local=$browser->request($base.'central-sso.php',['ticket'=>'valid-ticket']);check($local['status']===303&&str_contains($local['location'],'process=ato'),'Local ticket maps assigned process.');
    check($browser->request($local['location'])['status']===200,'Local grant validation.');check($browser->request($central['logout'])['location']==='/nbc-central/index.php','Local logout portal destination.');
    check($browser->request($base.'central-sso.php',['ticket'=>'valid-ticket'])['status']===403,'Local ticket replay rejected.');
    $audits=array_map(static fn($line)=>json_decode($line,true),file($fixture.'/audit.jsonl',FILE_IGNORE_NEW_LINES));
    foreach(['NBC Central sign-in','NBC Central application sign-out','Successful login'] as $description)check((bool)array_filter($audits,static fn($row)=>$row['description']===$description),'Native audit retained: '.$description);
    echo 'Rationalization SSO: '.$checks." checks passed (fixture SQL/CSV and connector; no live database or NAS).\n";
}catch(Throwable $error){
    fwrite(STDERR,'FAIL: '.$error->getMessage()."\n");if(is_file($fixture.'/server.log'))fwrite(STDERR,implode('',array_slice(file($fixture.'/server.log'),-18)));$failed=true;
}finally{
    if(is_resource($server)){proc_terminate($server);proc_close($server);}removeFixture($fixture);
}
exit($failed?1:0);

