<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

final class CisMail
{
    public static function seal(array $data): string
    {
        return Crypt::encryptString(json_encode($data,JSON_THROW_ON_ERROR));
    }

    public static function open(string $value): array
    {
        return json_decode(Crypt::decryptString($value),true,512,JSON_THROW_ON_ERROR);
    }

    public static function oauth(object $account): array
    {
        $cfg=self::open($account->configuration);
        if ($account->provider==='microsoft') {
            $root='https://login.microsoftonline.com/'.$cfg['tenant'].'/oauth2/v2.0/';
            return [$cfg,$root.'authorize',$root.'token','openid email profile offline_access User.Read Mail.Send'];
        }

        return [$cfg,'https://accounts.google.com/o/oauth2/v2/auth','https://oauth2.googleapis.com/token',
            'openid email https://www.googleapis.com/auth/gmail.send'];
    }

    public static function authorize(object $account,string $code,string $verifier,string $redirect): void
    {
        [$cfg,,$tokenUrl]=self::oauth($account);
        $token=self::token($cfg,$tokenUrl,['grant_type'=>'authorization_code','code'=>$code,
            'code_verifier'=>$verifier,'redirect_uri'=>$redirect]);
        $provider=Http::withToken($token['access_token'])->timeout(15);
        if ($account->provider==='microsoft') {
            $profile=$provider->get('https://graph.microsoft.com/v1.0/me',['$select'=>'mail,userPrincipalName'])->throw()->json();
            $emails=[$profile['mail']??null,$profile['userPrincipalName']??null];
        } else {
            $profile=$provider->get('https://openidconnect.googleapis.com/v1/userinfo')->throw()->json();
            $emails=($profile['email_verified']??false)?[$profile['email']??null]:[];
        }
        $matches=array_map(fn ($s)=>strtolower((string)$s),$emails);
        if (!in_array(strtolower($account->sender),$matches,true) || empty($token['refresh_token'])) {
            throw new \RuntimeException('A conta autorizada é diferente do remetente ou não concedeu acesso de renovação.');
        }
        DB::table('cis_mail_accounts')->where('id',$account->id)->where('configuration',$account->configuration)->update([
            'authorization'=>self::seal($token),'tested_at'=>null,'updated_at'=>now(),
        ]);
    }

    private static function token(array $cfg,string $url,array $params): array
    {
        $t=Http::asForm()->timeout(15)->post($url,$params+['client_id'=>$cfg['client_id'],
            'client_secret'=>$cfg['secret']])->throw()->json();
        if (empty($t['access_token'])) throw new \RuntimeException('O fornecedor não autorizou a ligação.');
        return ['access_token'=>$t['access_token'],'refresh_token'=>$t['refresh_token']??null,
            'expires_at'=>time()+(int)($t['expires_in']??0)];
    }

    private static function accessToken(int $id): string
    {
        return DB::transaction(function () use ($id) {
            $account=DB::table('cis_mail_accounts')->where('id',$id)->lockForUpdate()->first();
            if (!$account || !$account->authorization) throw new \RuntimeException('Autorize primeiro a conta.');
            $t=self::open($account->authorization);
            if ($t['expires_at']>time()+60) return $t['access_token'];
            [$cfg,,$url]=self::oauth($account);
            $fresh=self::token($cfg,$url,['grant_type'=>'refresh_token','refresh_token'=>$t['refresh_token']]);
            $fresh['refresh_token']??=$t['refresh_token'];
            DB::table('cis_mail_accounts')->where('id',$id)->update(['authorization'=>self::seal($fresh)]);
            return $fresh['access_token'];
        });
    }

    public static function send(object $account,string $to,string $subject,string $body): void
    {
        $email=(new Email())->from($account->sender)->to($to)->subject($subject)->text($body);
        if ($account->provider==='smtp') {
            $cfg=self::open($account->configuration);
            $transport=new EsmtpTransport($cfg['host'],(int)$cfg['port'],$cfg['security']==='tls'?true:null);
            $transport->setRequireTls(true);
            $transport->getStream()->setTimeout(15);
            $transport->setUsername($cfg['username'])->setPassword($cfg['secret']);
            $transport->send($email);
            return;
        }
        $client=Http::withToken(self::accessToken((int)$account->id))->timeout(20);
        if ($account->provider==='microsoft') {
            $client->post('https://graph.microsoft.com/v1.0/me/sendMail',['message'=>[
                'subject'=>$subject,'body'=>['contentType'=>'Text','content'=>$body],
                'toRecipients'=>[['emailAddress'=>['address'=>$to]]],
            ]])->throw();
            return;
        }
        $raw=rtrim(strtr(base64_encode($email->toString()),'+/','-_'),'=');
        $client->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send',['raw'=>$raw])->throw();
    }
}
