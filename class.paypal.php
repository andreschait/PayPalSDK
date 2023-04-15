<?php
/******************************************************************************
*                                  PayPal SDK
*******************************************************************************
*      Author:     Andres Chait
*      Email:      contact@andres.co.il
*      Website:    https://www.andres.co.il
*
*      Version:    2.0.0
*      Copyright:  (c) 2020 - Andres Chait
*                  You are free to use, distribute, and modify this software 
*                  under the terms of the GNU General Public License. See the
*                  included LICENCE file.
*    
/******************************************************************************/
class PayPal{
	private $clientID = null;
	private $secret = null;
	private $token = 'test';
	private $tokenSB = 'A21AALJb8rGp37gkim88PAVJ2McKz_6l-g51XjVDUJt_eO5xNk9zciEHJW2Tukwra3mlQq4-EEVZjZSzKiD5oixKwIzwhO5Cg';
	private $isSandbox = false;
	public $error = [];

	public function __construct($clientID,$secret,$sandbox=false){
		$this->clientID = $clientID;
		$this->secret = $secret;
		$this->isSandbox = $sandbox;

		//Test Token
		$res = $this->makeAPICall('CheckToken');

		//If Wrong Token Get New One
		if(isset($res['error']) && $res['error']=='invalid_token'){
			$res = $this->makeAPICall('GetToken',['grant_type'=>'client_credentials']);

			if(isset($res['error'])){
				$this->error = ['errorCode'=>'002','errorMessage'=>$res['error_description']];
				return false;
			}

			if(isset($res['access_token'])){
				if($this->isSandbox){
					$this->tokenSB = $res['access_token'];
				}else{
					$this->token = $res['access_token'];
				}
				$this->saveToken($res['access_token']);
			}
		}
		return true;
	}

	private function makeAPICall($method,$payload=[]){
		$methods = [
			'CheckToken'=>[
				'path'=>'v1/notifications/webhooks',
				'method'=>'GET'
			],
			'GetToken'=>[
				'path'=>'v1/oauth2/token',
				'method'=>'POST'
			],
			'CreateOrder'=>[
				'path'=>'v2/checkout/orders',
				'method'=>'POST'
			],
			'CheckOrder'=>[
				'path'=>'v2/checkout/orders/'.(isset($payload['token'])?$payload['token']:''),
				'method'=>'GET'
			],
			'ConfirmOrder'=>[
				'path'=>'v2/checkout/orders/'.(isset($payload['token'])?$payload['token'].'/capture':''),
				'method'=>'POST'
			]
		];

		if(!isset($methods[$method])){
			$this->error = ['errorCode'=>'001','errorMessage'=>'wrong method provided'];
			return;
		}

		$ch = curl_init('https://api.'.($this->isSandbox?'sandbox.':'').'paypal.com/'.$methods[$method]['path']);

		curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
		curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,15);
		if($methods[$method]['method']=='POST'){
			curl_setopt($ch,CURLOPT_CUSTOMREQUEST,'POST');
			if($payload){
				curl_setopt($ch,CURLOPT_POSTFIELDS,$method=='GetToken'?http_build_query($payload):json_encode($payload,JSON_UNESCAPED_UNICODE));
			}
		}
		if($method=='GetToken'){
			curl_setopt($ch,CURLOPT_HTTPHEADER,[
				'Authorization: Basic '.base64_encode($this->clientID.':'.$this->secret),
				'application/x-www-form-urlencoded']
			);
		}else{
			curl_setopt($ch,CURLOPT_HTTPHEADER,[
				'Authorization: Bearer '.($this->isSandbox?$this->tokenSB:$this->token),
				'Content-Type: application/json']
			);	
		}

		$res = curl_exec($ch);
		curl_close($ch);
		return json_decode($res,true);
	}

	public function checkOrder($token){
		$res = $this->makeAPICall('CheckOrder',['token'=>$token]);
		if(isset($res['status'])){
			return $res;
		}else{
			return ['status'=>'error'];
		}
	}

	public function confirmOrder($token){
		$res = $this->makeAPICall('ConfirmOrder',['token'=>$token]);
		if(isset($res['status']) && $res['status']=='COMPLETED'){
			return $res;
		}else if(isset($res['details'])){
			return $res['details'][0]['issue'];
		}else{
			return 'ERROR';
		}
	}

	public function createOrder($crt=[],$cntxt=[]){
		$app_context = ['landing_page'=>'BILLING','user_action'=>'PAY_NOW'];
		$cur = isset($crt['currency']) && strlen($crt['currency'])==3?$crt['currency']:'USD';

		foreach(['brand_name','return_url','cancel_url'] as $cntxt){
			if(isset($crt[$cntxt])){
				$app_context[$cntxt] = $crt[$cntxt];
			}
		}

		$terminalLoad = [
			'intent'=>'CAPTURE',
			'application_context'=>$app_context,
			'purchase_units'=>[
				[
					'reference_id'=>time(),
					'amount'=>[
						'currency_code'=>$cur,
						'breakdown'=>[
							'item_total'=>['currency_code'=>$cur],
							'shipping'=>['currency_code'=>$cur],
							'tax_total'=>['currency_code'=>$cur],
						]
					],
					'items'=>[]
				]
			]
		];
		
		if(isset($crt['items'])){
			$totalAmount = 0;
			$totalTax = 0;
			$totalShipping = isset($crt['shipping'])?floatval($crt['shipping']):0;

			foreach($crt['items'] as $cItem){
				if(!isset($cItem['price']) || !isset($cItem['qty'])){
					continue;
				}

				$itemPrice = floatval($cItem['price']);
				$itemQty = floatval($cItem['qty']);
				$itemTax = ($itemPrice*(isset($crt['tax'])?floatval($crt['tax']):0))/100;

				$terminalLoad['purchase_units'][0]['items'][] = [
					'sku'=>isset($cItem['sku'])?$cItem['sku']:'',
					'description'=>isset($cItem['details'])?$cItem['details']:'',
					'name'=>isset($cItem['name'])?$cItem['name']:'',
					'unit_amount'=>[
						'currency_code'=>$cur,
						'value'=>number_format($itemPrice,2,'.','')
					],
					'quantity'=>$itemQty,
					'tax'=>[
						'currency_code'=>$cur,
						'value'=>number_format($itemTax,2,'.','')
					]
				];

				$totalAmount += $itemPrice*$itemQty;
				$totalTax += round($itemTax,2)*$itemQty;
			}
			$terminalLoad['purchase_units'][0]['amount']['value'] = number_format($totalAmount+$totalShipping+$totalTax,2,'.','');
			$terminalLoad['purchase_units'][0]['amount']['breakdown']['item_total']['value'] = number_format($totalAmount,2,'.','');
			$terminalLoad['purchase_units'][0]['amount']['breakdown']['shipping']['value'] = number_format($totalShipping,2,'.','');
			$terminalLoad['purchase_units'][0]['amount']['breakdown']['tax_total']['value'] = number_format($totalTax,2,'.','');
		}

		$res = $this->makeAPICall('CreateOrder',$terminalLoad);
		return $res;
	}

	private function saveToken($token){
		$newFile = '';
		$phpClass = fopen(__FILE__,'r');
	    while(($phpLine=fgets($phpClass,4096))!==false){
			preg_match('/\$token'.($this->isSandbox?'SB':'').' .+;/',$phpLine,$matches);
			if($matches){
				$phpLine = str_replace($matches[0],'$token'.($this->isSandbox?'SB':'').' = \''.$token.'\';',$phpLine);
			}
			$newFile.=$phpLine;
		}
		fclose($phpClass);
		file_put_contents(__FILE__,$newFile);
	}
}