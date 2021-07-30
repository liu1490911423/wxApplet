<?php
/**
 * Created by PhpStorm.
 * User: lhx
 * Date: 2021/7/30 0030
 * Time: 16:07
 */

namespace Applet;

use Predis\Client;

class WxApplet
{

    private $url = 'https://api.weixin.qq.com';
    private $authorizedConnection = 'https://mp.weixin.qq.com/cgi-bin/componentloginpage?';
    private $redirect_uri = '/view/applets/publishApplet';//授权成功回调地址
    protected $appId;
    protected $secret;
    protected $cache;
    protected $cacheKey;
    protected $http;
    protected $tokenJsonKey = 'access_token';
    protected $tokenPrefix = 'easywechat.common.access_token.';
    protected $ticketPrefix = 'easywechat.open_platform.component_verify_ticket.';
    protected $autoCodePrefix = 'easywechat.open_platform.pre_auth_code.';
    protected $authorizerPrefix = 'easywechat.open_platform.authorizer_access_token.';
    protected $authorizerRefreshPrefix = 'easywechat.open_platform.authorizer_refresh_token.';
    protected $accessToken;
    protected $authorizerAccessToken;
    protected $authorizerAppId;
    protected $redis_url = '';
    protected $redis = '';

    public $Link_URL=[
        1=>'/cgi-bin/component/api_component_token',//token获取
        2=>'/cgi-bin/component/api_create_preauthcode',//预授权码获取
        3=>'/cgi-bin/component/api_query_auth',//使用授权码获取授权信息
        4=>'/cgi-bin/open/bind',//小程序绑定到开放平台帐号下
        5=>'/wxa/commit',//上传小程序代码
        6=>'/cgi-bin/component/api_authorizer_token',//获取/刷新接口调用令牌
        7=>'/cgi-bin/component/api_get_authorizer_info',//获取授权方的帐号基本信息
        8=>'/wxa/submit_audit',//提交审核
        9=>'/wxa/get_latest_auditstatus',//获取最新审核状态
        10=>'/wxa/undocodeaudit',//小程序审核撤回
        11=>'/wxa/release',//发布已通过审核的小程序
        12=>'/wxa/get_qrcode',//获取体验版二维码
        13=>'/wxa/gettemplatedraftlist',//获取代码草稿列表
        14=>'/wxa/addtotemplate',//获取将草稿添加到代码模板库
        15=>'/wxa/gettemplatelist',//获取代码模板列表
        16=>'/wxa/setwebviewdomain',//设置业务域名
        17=>'/cgi-bin/open/unbind',//小程序从开放平台帐号下解绑
        18=>'/cgi-bin/open/create',//创建开放平台帐号并绑定公众号/小程序
        19=>'/wxa/bind_tester',//绑定体验者
        20=>'/wxa/unbind_tester',//解除绑定体验者
    ];

    public function __construct($config)
    {
        $this->appId = $config['applet_app_id'];
        $this->secret = $config['applet_app_secret'];
        $this->accessToken = $this->getComponentAccessToken();
        $this->redis_url = $config['redis_url'];

        $this->redis = new Client($this->redis_url);
        if ($config['third_applet_app_id']) {
            $this->authorizerAppId = $config['third_applet_app_id'];
            $this->authorizerAccessToken = $this->getAuthorizerAccessToken($config['third_applet_app_id']);
        }
    }

    /**
     * 获取保存token
     */
    public function getComponentAccessToken()
    {
        //获取ticket
        $accessToken = $this->redis->get($this->tokenPrefix.$this->appId);
        if (!$accessToken) {
            $component_verify_ticket = $this->redis->get($this->ticketPrefix . $this->appId);
            $data = [
                'component_appid' => $this->appId,
                'component_appsecret' => $this->secret,
                'component_verify_ticket' => $component_verify_ticket,
            ];
            $post_data = json_encode($data);

            $res = $this->curlData($this->url . $this->Link_URL[1], $post_data, 'POST');
            $result = $this->fixReturn($res);
            if (!$result['ret']) {
                $accessToken = $result['component_access_token'];
                $this->redis->set($this->tokenPrefix . $this->appId, $accessToken, $result['expires_in']);
            } else {
                $accessToken = '';
            }

        }
        return $accessToken;
    }

    /**
     * 获取预授权码
     */
    public function getPreAuthCode()
    {
        $preAuthCode = $this->redis->get($this->autoCodePrefix.$this->appId);
        if(!$preAuthCode){
            $data = [
                'component_appid' => $this->appId,
            ];
            $post_data = json_encode($data);
            $post_url = $this->url . $this->Link_URL[2] . '?component_access_token=' . $this->accessToken;
            $res = $this->curlData($post_url, $post_data, 'POST');

            $result = $this->fixReturn($res);

            if (!$result['ret']) {
                $preAuthCode = $result['pre_auth_code'];
                $this->redis->set($this->autoCodePrefix . $this->appId, $preAuthCode, $result['expires_in']);
            } else {
                $preAuthCode = '';
            }
        }
        return $preAuthCode;

    }

    /**
     * 获取授权连接
     */
    public function getAuthorizedConnection()
    {
        $preAuthCode = $this->getPreAuthCode();
        if (!$preAuthCode) {
            return [-1, '预授权码错误！'];
        }
        $url = $this->authorizedConnection.'component_appid='.$this->appId.'&pre_auth_code='.$preAuthCode.'&auth_type=2';
        $url .= '&redirect_uri='.$this->redirect_uri;
        return [0, $url];
    }

    /**
     * 使用授权码获取授权信息
     * @param $authorization_code
     * @return mixed
     */
    public function getQueryAuth($authorization_code)
    {
        $data = [
            'component_appid' => $this->appId,
            'authorization_code' => $authorization_code,
        ];
        $post_data = json_encode($data);
        $post_url = $this->url . $this->Link_URL[3] . '?component_access_token=' . $this->accessToken;
        $res = $this->curlData($post_url, $post_data, 'POST');

        $result = $this->fixReturn($res);

        return $result;
    }

    /**
     * @param $appId
     * @return string
     * 获取令牌authorizer_access_token
     */
    public function getAuthorizerAccessToken()
    {
        $authorizerAccessToken = $this->redis->get($this->authorizerPrefix.$this->authorizerAppId);
        $authorizerRefreshToken = $this->redis->get($this->authorizerRefreshPrefix.$this->authorizerAppId);
        if(!$authorizerAccessToken){
            $data = [
                'component_appid' => $this->appId,
                'authorizer_appid' => $this->authorizerAppId,
                'authorizer_refresh_token' => $authorizerRefreshToken,
            ];
            $post_data = json_encode($data);
            $post_url = $this->url . $this->Link_URL[6] . '?component_access_token=' . $this->accessToken;
            $res = $this->curlData($post_url, $post_data, 'POST');

            $result = $this->fixReturn($res);

            if (!$result['ret']) {
                $authorizerAccessToken = $result['authorizer_access_token'];
                $authorizerRefreshToken = $result['authorizer_refresh_token'];
                $this->redis->set($this->authorizerPrefix . $this->authorizerAppId, $authorizerAccessToken, $result['expires_in']);
                $this->redis->set($this->authorizerRefreshPrefix . $this->authorizerAppId, $authorizerRefreshToken, -1);
            } else {
                $authorizerAccessToken = '';
            }
        }
        return $authorizerAccessToken;
    }

    /**
     * 小程序绑定到开放平台帐号下
     * @return mixed
     */
    public function setBind()
    {
        $data = [
            'appid' => $this->authorizerAppId,
            'open_appid' => $this->appId,
        ];
        $post_data = json_encode($data);
        $post_url = $this->url . $this->Link_URL[4] . '?access_token=' . $this->authorizerAccessToken;
        $res = $this->curlData($post_url, $post_data, 'POST');

        $result = $this->fixReturn($res);

        return $result;
    }

    /**
     * 小程序所绑定的开放平台帐号
     * @return mixed
     */
    public function getBind()
    {
        $data = [
            'appid' => $this->authorizerAppId,
        ];
        $post_data = json_encode($data);
        $post_url = $this->url . $this->Link_URL[18] . '?access_token=' . $this->authorizerAccessToken;
        $res = $this->curlData($post_url, $post_data, 'POST');
        $result = $this->fixReturn($res);

        return $result;
    }

    /**
     * 小程序从开放平台帐号下解绑
     * @return mixed
     */
    public function unbind()
    {
        $data = [
            'appid' => $this->authorizerAppId,
            'open_appid' => $this->appId,
        ];
        $post_data = json_encode($data);
        $post_url = $this->url . $this->Link_URL[17] . '?access_token=' . $this->authorizerAccessToken;
        $res = $this->curlData($post_url, $post_data, 'POST');

        $result = $this->fixReturn($res);

        return $result;
    }

    /**
     * 上传小程序代码
     * @param $ext_json
     * @param $template_id
     * @param $user_version
     * @return mixed
     */
    public function commit($ext_json, $template_id, $user_version)
    {
        $data = [
            'template_id' => $template_id,
            "user_version"=> $user_version,
            "user_desc"=> "test",
            "ext_json" => json_encode($ext_json)
        ];
        $post_data = json_encode($data);
        $post_url = $this->url . $this->Link_URL[5] . '?access_token=' . $this->authorizerAccessToken;
        $res = $this->curlData($post_url, $post_data, 'POST');

        $result = $this->fixReturn($res);

        return $result;
    }

    /**
     * 获取授权方的帐号基本信息
     * @return mixed
     */
    public function getAuthorizerInfo()
    {
        $data = [
            'component_appid' => $this->appId,
            'authorizer_appid' => $this->authorizerAppId,
        ];

        $post_data = json_encode($data);
        $post_url = $this->url . $this->Link_URL[7] . '?component_access_token=' . $this->accessToken;
        $res = $this->curlData($post_url, $post_data, 'POST');

        $result = $this->fixReturn($res);

        return $result;
    }

    /**
     * 提交审核
     * @param $data
     * @return mixed
     */
    public function submitAudit($data)
    {
        $post_data = json_encode($data);
        $post_url = $this->url . $this->Link_URL[8] . '?access_token=' . $this->authorizerAccessToken;
        $res = $this->curlData($post_url, $post_data, 'POST');

        $result = $this->fixReturn($res);

        return $result;
    }

    /**
     * 获取最新审核状态和撤销审核
     * @return mixed
     */
    public function operationApplet($code)
    {
        $data = [
            'access_token' => $this->authorizerAccessToken
        ];
        $post_url = $this->url . $this->Link_URL[9];
        $res = $this->curlData($post_url, $data, 'GET');
        $result = $this->fixReturn($res);
        return $result;
    }

    /**
     * 获取体验二维码
     * @return mixed
     */
    public function getQrcode()
    {
        $data = [
            'access_token' => $this->authorizerAccessToken
        ];
        $post_url = $this->url . $this->Link_URL[12];
        $res = $this->curlData($post_url, $data, 'GET');
        return $res;
    }

    /**
     * 获取代码草稿列表
     * @return mixed
     */
    public function gettemplatedraftlist()
    {
        $data = [
            'access_token' => $this->accessToken
        ];
        $post_url = $this->url . $this->Link_URL[13];
        $res = $this->curlData($post_url, $data, 'GET');
        $result = $this->fixReturn($res);
        return $result;
    }

    /**
     * 将草稿添加到代码模板库
     * @param $draft_id
     * @return mixed
     */
    public function addtotemplate($draft_id)
    {
        $data = [
            'draft_id'=>$draft_id
        ];
        $post_data = json_encode($data);
        $post_url = $this->url . $this->Link_URL[14].'?access_token=' . $this->accessToken;
        $res = $this->curlData($post_url, $post_data, 'POST');
        $result = $this->fixReturn($res);
        return $result;
    }

    /**
     * 获取代码草稿列表
     * @return mixed
     */
    public function gettemplatelist()
    {
        $data = [
            'access_token' => $this->accessToken,
        ];
        $post_url = $this->url . $this->Link_URL[15];
        $res = $this->curlData($post_url, $data, 'GET');
        $result = $this->fixReturn($res);
        return $result;
    }

    /**
     * 发布审核通过版本
     * @return mixed
     */
    public function release()
    {
        $post_url = $this->url . $this->Link_URL[11] .'?access_token=' . $this->authorizerAccessToken;
        $res = $this->curlData($post_url, '{}', 'POST');
        $result = $this->fixReturn($res);
        return $result;
    }

    /**
     * 设置业务域名
     * @param $action
     * @param $webviewdomain
     * @return mixed
     */
    public function setwebviewdomain($action, $webviewdomain)
    {
        $data = [
            'action' => $action,
            'webviewdomain' => $webviewdomain,
        ];
        $post_data = json_encode($data);
        $post_url = $this->url . $this->Link_URL[16] .'?access_token=' . $this->authorizerAccessToken;
        $res = $this->curlData($post_url, $post_data, 'POST');
        $result = $this->fixReturn($res);
        return $result;
    }

    /**
     * 绑定体验者
     * @param $type
     * @param $tester
     * @return mixed
     */
    public function bindTester($type, $tester)
    {
        $data = [
            'wechatid' => $tester,
        ];
        $post_data = json_encode($data);
        $num = $type==1?19:20;
        $post_url = $this->url . $this->Link_URL[$num] .'?access_token=' . $this->authorizerAccessToken;
        $res = $this->curlData($post_url, $post_data, 'POST');
        $result = $this->fixReturn($res);
        return $result;
    }



    /**
     * 接口请求
     * @param $url
     * @param $data
     * @param $method
     * @return bool|string
     */
    function curlData($url,$data,$method)
    {
        //初始化
        $ch = curl_init();
        $headers = ['Content-Type: application/json'];
        if($method == 'GET'){
            $querystring = http_build_query($data);
            $url = $url.'?'.$querystring;
        }
        // 请求头，可以传数组
        curl_setopt($ch, CURLOPT_URL,$url);
        curl_setopt($ch, CURLOPT_HTTPHEADER,$headers);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // 执行后不直接打印出来
        if($method == 'POST'){
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST,'POST');     // 请求方式
            curl_setopt($ch, CURLOPT_POST, true);        // post提交
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);   // post的变量
        }
        if($method == 'PUT'){
            curl_setopt ($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            curl_setopt($ch, CURLOPT_POSTFIELDS,$data);
        }
        if($method == 'DELETE'){
            curl_setopt ($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
            curl_setopt($ch, CURLOPT_POSTFIELDS,$data);
        }
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 跳过证书检查
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // 不从证书中检查SSL加密算法是否存在
        $output = curl_exec($ch); //执行并获取HTML文档内容
        curl_close($ch); //释放curl句柄
        return $output;
    }

    /**
     * 返回信息处理
     * @param $res
     * @return mixed
     */
    function fixReturn($res)
    {
        $res = json_decode($res, true);
        $result = $res;
        if (isset($result['errcode'])&&$result['errcode']!=0) {
            $result['ret'] = -1;
        } else {
            $result['ret'] = 0;
        }
        return $result;
    }

}