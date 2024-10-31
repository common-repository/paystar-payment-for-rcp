<?php

if (!class_exists('PayStar_Payment_Helper'))
{
	class PayStar_Payment_Helper
	{
		public function __construct($terminal = '')
		{
			$this->terminal = $terminal;
		}
		public function paymentRequest($data)
		{
			$result = $this->curl('https://core.paystar.ir/api/pardakht/create', $data);
			if (is_object($result) && isset($result->status)) {
				$this->data = $result->data;
				if ($result->status == 1) {
					return $result->data->ref_num;
				} else {
					$this->error = $result->message;
				}
			} else {
				$this->error = 'خطا در ارتباط با درگاه پی استار';
			}
			return false;
		}
		public function paymentVerify($data)
		{
			if ($data['status'] == 1) {
				$result = $this->curl('https://core.paystar.ir/api/pardakht/verify', array(
						'amount'  => $data['amount'],
						'ref_num' => $data['ref_num'],
					));
				if (is_object($result) && isset($result->status)) {
					$this->data = $result->data;
					if ($result->status == 1) {
						$this->txn_id = $data['tracking_code'];
						return true;
					} else {
						$this->error = $result->message;
					}
				} else {
					$this->error = 'خطا در ارتباط با درگاه پی استار';
				}
			} else {
				$this->error = 'تراکنش توسط کاربر لغو شد';
			}
			return false;
		}
		public function curl($url, $data)
		{
			$result = wp_remote_post($url, array('body' => wp_json_encode($data), 'headers' => $h = array('Authorization' => 'Bearer '.$this->terminal, 'Content-Type' => 'application/json'), 'sslverify' => false));
			if (!is_wp_error($result))
			{
				return json_decode($result['body']);
			}
		}
	}
}
