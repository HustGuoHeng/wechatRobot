<?php
	ini_set('max_execution_time', 3000);
	echo "<pre>";
    $wechatBot = new wechatBot();
    $wechatBot->run();

class wechatBot
{


    public $uuid = false;
    	
    //用于waitForLogin
    protected $waitForCheck = false; //用于标志用户是否扫描
    protected $tid = 1;

    //手机扫描成功后获取的参数
    protected $redirectUrl;
    protected $hostUrl;
    protected $loginSuccessCoreKey = [];
    protected $cookie;
    //用于初始化以及获取信息的参数
    protected $baseRequest = [];
	protected $baseInfo; //BaseResponse Count ContactList SyncKey User ChatSet SKey ClientVersion SystemTime GrayScale InviteStartCount MPSubscribeMsgCount MPSubscribeMsgList ClickReportInterval
	protected $deviceID = 'e159973572418266';
	//保存webWeixinGetContact获取到的用户信息
	protected $webWeixinGetContact;

    /**
     * 	主体代码(仅仅供测试使用，具体的业务流程再行规划)
     */
    public function run() {
    	echo "微信网页自定义登陆程序<br>";
    	self::getUuid(); // 获取$uuid
    	//获取二维码
	    $src_url = self::getQRcodeUrl();
	    echo "<img style=\"width:200px\"src=\"$src_url\"></img>";//生成二维码图片
	    ob_flush();
	    flush();
	    //检测扫描状态
	    while (self::waitForLogin() != 200) {
    		true;
    	}
    	//获取扫描成功后关键信息
    	self::getCoreKey();
    	//网页微信初始化
    	self::webWeixinInit();
    	//获取用户常用联系人信息
    	// self::webWeixinGetContact();
        //获取群组详细信息
        self::webWeixinBatchGetContent();
    }
    /**
     * 获取uuid
     */
    public function getUuid() {
        $getUuidUrl = "https://login.weixin.qq.com/jslogin?appid=wx782c26e4c19acffb&redirect_uri=https%3A%2F%2Fwx.qq.com%2Fcgi-bin%2Fmmwebwx-bin%2Fwebwxnewloginpage&fun=new&lang=en_US&_=1452859503801";

        while (!$this->uuid) {
	        $result = self::curlRequest($getUuidUrl);
	        $result = preg_match('/uuid = "([a-zA-Z0-9_]*={2})"/', $result, $matches);
	        @$uuid =  $matches['1'];
	        $this->uuid = $uuid;
        }
    }
    /**
     * 获取二维码
     */
    public function getQRcodeUrl() {   
    	$url = "https://login.weixin.qq.com/qrcode/" . $this->uuid;
        return $url;                                                                                                                 
    }
    /**
     * 获取连接状态
     */
    public function waitForLogin() {
        $now_time = time();
        $url = "https://login.weixin.qq.com/cgi-bin/mmwebwx-bin/login?loginicon=true&uuid=" . $this->uuid . "&tip=" . $this->tid . "&r=" . $now_time . "&_=1452859503803";
        $result = $this->curlRequest($url, false, array(), 20);
        preg_match('/window.code=([0-9]*)/',$result,$matches);
        
        @$code = $matches[1];
        if ($code == '201' && !$this->waitForCheck){
            echo "<br>";
            $this->waitForCheck =  true;
            $this->tid = 0;
            echo "扫描成功，请在手机确认登录！";
            ob_flush();
            flush();
     	} else if ($code == '200') {
     		preg_match('/window.redirect_uri=\"(\S*)\"/',$result,$matches);
            $this->redirectUrl = $matches['1'];
            $this->hostUrl = parse_url($this->redirectUrl, PHP_URL_HOST);       
            echo "<br>登录成功，正在获取关键信息……";
            ob_flush();
    		flush();
        }else if ($code == '408') {
           break;
        }
        return $code;
    }
    /**
     *	连获取连接成功后关键的信息
     */
    public function getCoreKey() {
    	$result = self::curlRequest($this->redirectUrl, false, [], 60, 1);
    	preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $result, $matches);
		$cookies = array();
		foreach($matches[1] as $item) {
    		parse_str($item, $cookie);
    		$cookies = array_merge($cookies, $cookie);
		}
		$this->cookie = $cookies;

		preg_match('/\<error\>\S*\<\/error\>/', $result, $match);
    	$xml = simplexml_load_string($match[0]);
    	$arr = [];
    	foreach ($xml as $key => $value) {
    		$arr[$key] = (string)$value;
    	}
    	$this->loginSuccessCoreKey = $arr;
    	echo "<br>关键信息已获取，正在进行初始化……";
    	ob_flush();
    	flush();
    }
    //初始化信息
    public function webWeixinInit() {

    	$url = "https://$this->hostUrl/cgi-bin/mmwebwx-bin/webwxinit?pass_ticket=" . $this->loginSuccessCoreKey['pass_ticket'] . "&skey=" . $this->loginSuccessCoreKey['skey'] . "&r=" . time();

		$this->baseRequest['Uin'] = $this->loginSuccessCoreKey['wxuin'];
		$this->baseRequest['Sid'] = $this->loginSuccessCoreKey['wxsid'];
    	$this->baseRequest['Skey'] = $this->loginSuccessCoreKey['skey'];
    	$this->baseRequest['DeviceID'] = $this->deviceID;


    	$params = array('BaseRequest' => $this->baseRequest);
    	$params = json_encode($params);
    	//todo 这里会出现获取不到信息的情况，尚不明白具体原因，但影响不大
    	$result = self::curlRequest($url, true, $params);
    	$result = json_decode($result);
    	if (!$result->BaseResponse->Ret) {
    		$this->baseInfo = $result;
    		$this->baseRequest['skey'] = $this->baseInfo->SKey;
	    	echo "<br>初始化成功！获取信息中……";
	    	ob_flush();
	    	flush();
	    } else {
	    	self::wrongResponse("初始化失败，五秒后页面即将刷新，请重新扫码登录！");
	    }
    }
    /**
     *	获取常用联系人信息
     *  todo 同样会出现获取信息失败的情况，尚不清楚具体原因
     */
    public function webWeixinGetContact() {
    	sleep(1);
    	$url = "https://$this->hostUrl/cgi-bin/mmwebwx-bin/webwxgetcontact?pass_ticket=".$this->loginSuccessCoreKey['pass_ticket'] . "&r=1467445194420&seq=0&skey=" . (string)$this->baseInfo->SKey;

    	$cookie_str = self::changeCookieToStr();
    	$result = self::curlRequest($url, false, [], 5, 0, $cookie_str);
    	$result = json_decode($result); //BaseResponse MemberCount MemberList Seq
    	if (!$result->BaseResponse->Ret) {
    		$this->webWeixinGetContact = $result;
    		echo "<br>常用联系人信息获取成功";
    	} else {
	    	self::wrongResponse("常用联系人信息获取失败，页面将在五秒后刷新，请重新扫码登录！");
    	}


    }
    /**
     * 获取用户信息(功能实现，根据具体应用在修改)
     */
   	public function webWeixinBatchGetContent() {
   		$url = "https://$this->hostUrl/cgi-bin/mmwebwx-bin/webwxbatchgetcontact?type=ex&r=1453373586582&pass_ticket=" . $this->loginSuccessCoreKey['pass_ticket'];

        $list = [];
        foreach ($this->baseInfo->ContactList as $value) {
            $list[] = array(
                'EncryChatRoomId' => '',
                'UserName'   => $value->UserName
            );
        }
   		$params = array(
   			'BaseRequest' => $this->baseRequest,
   			'Count' => count($list),
   			'List'  => $list
   			);
   		$params = json_encode($params);
   		$cookie_str = self::changeCookieToStr();

   		$result = self::curlRequest($url, true, $params);
   		$result = json_decode($result);
   		echo "Now";
   		print_r($result); 
   
   	}
    /**
     * 获取用户头像
     * @param $username 微信对的用户的标示id
     * @return  $headImaUrl 用户头像的连接
     */
    public function getIcon($username) {
        $url = "https://$this->hostUrl/cgi-bin/mmwebwx-bin/webwxgeticon?seq=".time()."&username=".$username."&skey=" . (string)$this->baseInfo->SKey;
        return $url;    
    }
    /**
     * 获取群头像
     * @param $username 微信群标示id
     * @return  $headImaUrl 用户头像的连接
     */
    public function getHeadImgUrl($username) {
        $url = "https://$this->hostUrl/cgi-bin/mmwebwx-bin/webwxgetheadimg?seq=".time()."&username=".$username"&skey=" (string)$this->baseInfo->SKey;
        return $url;
    }
    /*
    * curl获取网页请求
    */
    public function curlRequest($url, $isPost = false, $params = array(), $timeOut = 60, $header = 0, $cookie = false) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        if ($isPost) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        }
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HEADER, $header);
        //curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeOut);
        if ($cookie) {
        	curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }
        print_r(curl_error ($ch));
        $result = curl_exec($ch);
        curl_close($ch);

        return $result;
    }

    /**
     * 获取当前网址
     */
    public function getServiceUrl() {
    	$url = 'http://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
    	return $url;
    }
    /**
     * 必要过程失败处理函数
     */
    public function wrongResponse($data) {
	    echo "<br>" . $data;
	    ob_flush();
	    flush();
	    echo "<script> location.href='".self::getServiceUrl()."';</script>"; 
    }
    /**
     * 将curl获取的cookie转换为字符串
     */
    public function changeCookieToStr() {
    	$cookie_str = '';
    	foreach ($this->cookie as $key => $value) {
    		$cookie_str .= $key . "=" . $value . ';';
    	}
    	return $cookie_str;
    }
}
