<?php

namespace sidoc;

use Exception;
use sidoc\RemoteCallTools;
use sidoc\WorkWechat;
use think\facade\Log;

// 腾迅CDN SSL证书部署
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Cdn\V20180606\CdnClient;
use TencentCloud\Cdn\V20180606\Models\UpdateDomainConfigRequest;
use think\facade\Env;
use Throwable;

/**
 * SSL证书检查和续期
 */
class SSLService{

    /**
     * 检查域名的SSL证书剩余有效时间，并自动续期
     *
     * @param [type] $domain 欲检查、申请SSL证书的域名
     * @param array $cdnDomainList 需要部署至腾迅云CDN的域名
     * @param string $verifyDomain 用于验证域名的SSL证书是否过期的域名，部分域名本身可能无法验证其SSL是否过期，如：user.sidoc.cn，验证其证书是否过期必须使用service.user.sidoc.cn,因为user.sidoc.cn域名本身可能暂未使用或未被解析
     * @return void
     */
    static public function check($domain,$cdnDomainList=[]){

        // 0.1> 获取SSL证书过期时间 -----------------------------
        $diffDay = self::getExpiredTime($domain);
        if($diffDay==false){
            return;
        }

        // 0.2> 当SSL证书有效期小于20天时，则申请或更新证书 -----------------------------------
        if($diffDay <=20){ 
            // 申请SSL证书
            if(self::applySSL($domain) !== true){
                return;
            }
            // 部署CDN证书
            foreach($cdnDomainList as $cdnDomain){
                self::deployToCDN($domain,$cdnDomain);
            }
            self::updateTask($domain,"证书申请完成，过期时间为".intval(self::getExpiredTime($domain))."天后",date("Y-m-d H:i:s"),"normal");
        }else{
            
            $des = "证书还有".intval($diffDay)."天过期，暂时无须更新";
            self::updateTask($domain,$des,date("Y-m-d H:i:s"),"normal");
        }
    }

    /**
     * 获取域名的过期时间
     *
     * @param [type] $domain
     * @return void
     */
    static private function getExpiredTime($domain){

        // 0.1> 获取SSL证书信息 -----------------------
        $command = "openssl x509 -in /var/www/ssl/$domain -noout -dates";
        exec($command,$out,$status); 
        // status是执行的状态 0为成功 1为失败
        if($status != 0){
           Log::error($out);
           $msg = "获取域名".$domain."的SSL证书过期时间时发生错误:".json_encode($out,JSON_UNESCAPED_UNICODE);  // json_encode默认会将中文转为unicode编码，JSON_UNESCAPED_UNICODE配置声明保留原有中文，不要转为unicode
           // 命令行程序抛出的异常不会被全局捕获，因此主动上报
           WorkWechat::reportException(new Exception($msg));
           self::updateTask($domain,$msg,date("Y-m-d H:i:s"),"error");
           return false;
        }

        // 0.2> 取出SSL证书的过期时间 -----------------------
        foreach($out as $item){
            if(strpos($item,'notAfter') !== false){
                $time = explode("=",$item)[1];
                $time = strtotime($time);
                $diff = $time - time();
                $diff = $diff/86400; // 秒转天
                return $diff;
            }
        }

        // 0.3> 若无法获取SSL证书过期时间，则上报错误 ------------
        $msg = "无法获取域名".$domain."的SSL证书过期时间";
        // 命令行程序抛出的异常不会被全局捕获，因此主动上报
        WorkWechat::reportException(new Exception($msg));
        self::updateTask($domain,$msg,date("Y-m-d H:i:s"),"error");
    }
    

    // 申请SSL证书，并完成本机安装
    static private function applySSL($domain){

        // 0.1> 更新SSL证书
        Log::info("开始更新SSL证书：".$domain);
        /**
         * 3.0版本以后的acme.sh默认申请的证书是ZeroSSL，而该证书目前在申请过程中有一定概率发生错误，因此可使用 --server letsencrypt 来指定申请 Let's Encrypt 证书；
         * --use-wget指定证书的下载方式，acme.sh默认使用的是curl；相比curl，wget更加专注于下载，因此可能速度会更快；
         */
        $command = "sudo /root/.acme.sh/acme.sh --issue --dns dns_ali -d '$domain' -d '*.$domain' --force --server letsencrypt";
        exec($command,$out,$status);   // exce使用详见：https://www.cnblogs.com/jianqingwang/p/6824380.html
        // status是执行的状态 0为成功 1为失败
        if($status != 0){
           Log::error($out);
           $msg = "更新域名".$domain."的SSL证书时发生错误:".json_encode($out,JSON_UNESCAPED_UNICODE);  // json_encode默认会将中文转为unicode编码，JSON_UNESCAPED_UNICODE配置声明保留原有中文，不要转为unicode
           // 命令行程序抛出的异常不会被全局捕获，因此主动上报
           WorkWechat::reportException(new Exception($msg));
           self::updateTask($domain,$msg,date("Y-m-d H:i:s"),"error");
           return false;
        }

        // 0.2> 安装SSL证书 (复制证书至/var/www/ssl目录，并重启Nginx)
        $command = 'sudo /root/.acme.sh/acme.sh --install-cert -d '.$domain.' --key-file /var/www/ssl/'.$domain.'.key --fullchain-file /var/www/ssl/'.$domain.' --reloadcmd "service nginx force-reload" --force';
        exec($command,$out,$status);
        if($status != 0){
           Log::error($out);
           $msg = "更新域名".$domain."的SSL证书时发生错误:".json_encode($out,JSON_UNESCAPED_UNICODE);  // json_encode默认会将中文转为unicode编码，JSON_UNESCAPED_UNICODE配置声明保留原有中文，不要转为unicode
           // 命令行程序抛出的异常不会被全局捕获，因此主动上报
           WorkWechat::reportException(new Exception($msg));
           self::updateTask($domain,$msg,date("Y-m-d H:i:s"),"error");
           return false;
        }
        return true;
    }

    // 部署SSL证书至腾迅CDN
    // 详见官网API：https://cloud.tencent.com/document/product/228/41116
    // 官方在线调试：https://console.cloud.tencent.com/api/explorer?Product=cdn&Version=2018-06-06&Action=UpdateDomainConfig&SignVersion=
    static public function deployToCDN($domain,$cndDomain){

        try{
            $cred = new Credential(tencenyun_cdn_id,tencenyun_cdn_key);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint("cdn.tencentcloudapi.com");
                
            $clientProfile = new ClientProfile();
            $clientProfile->setHttpProfile($httpProfile);
            $client = new CdnClient($cred, "", $clientProfile);
            
            $req = new UpdateDomainConfigRequest();
            $params = array(
                // "Action"=>"UpdateDomainConfig",
                // "Version"=>"2019-10-12",
                "Domain" => $cndDomain,
                "Https" => array(
                    "Switch" => "on", // https 配置开关
                    "Http2" => "on",  // http2 配置开关
                    "OcspStapling" => "on", // OCSP 配置开关
                    "Hsts" => array(
                        "Switch" => "on",
                        "MaxAge" => 31536000
                    ),
                    "CertInfo" => array(
                        "Certificate" => file_get_contents("/var/www/ssl/".$domain),
                        "PrivateKey" => file_get_contents("/var/www/ssl/".$domain.".key"),
                        "Message" => "由sidoc-admin更新于:".date("Y-m-d H:i:s")  // 证书备注信息
                    )
                )
            );
            $req->fromJsonString(json_encode($params,JSON_UNESCAPED_UNICODE));  // json_encode默认会将中文转为unicode编码，JSON_UNESCAPED_UNICODE配置声明保留原有中文，不要转为unicode
            $resp = $client->UpdateDomainConfig($req);
            print_r($resp->toJsonString());

            self::updateTask($domain,"CDN域名证书部署完成",date("Y-m-d H:i:s"),"normal");

        }catch(Throwable $e){
            // 命令行程序抛出的异常不会被全局捕获，因此主动上报
            WorkWechat::reportException($e);
            $des = "部署域名".$domain."的CDN域名证书时发生错误,CDN域名：".$cndDomain;
            self::updateTask($domain,$des,date("Y-m-d H:i:s"),"error");
            return false;
        }
        return true;
    }

    private static function updateTask($id,$des,$time,$executionStatus){
        $arr['id'] = $id;
        $arr['des'] = $des;
        $arr['time'] = $time;
        $arr['status'] = $executionStatus;
        RemoteCallTools::request(Env::get('SIDOC_ADMIN_SERVICE')."/task/updateTask",$arr);
    }

}