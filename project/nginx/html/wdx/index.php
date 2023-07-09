<?php
	//etri open api key
	const ACCESS_KEY = 'aad918b8-14fd-4773-a897-57a5734b2bc1';
	
	const HTTP_OP = array(
		'http'=>array(
			'method'  => 'POST',
			'header'=> 
				"Authorization: " . ACCESS_KEY . "\n" .
				"Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9\n" .
				//"Accept-Encoding: gzip, deflate, br\n" .
				"Accept-Language: ko,en;q=0.9,en-US;q=0.8\n".
				"Content-Type: application/json; charset=UTF-8\n".
				//"Content-Length: 128\n".
				"Host: aiopen.etri.re.kr:8443\n".
				"Origin: https://aiopen.etri.re.kr\n".
				"Referer: https://aiopen.etri.re.kr/\n".
				"User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/98.0.4758.102 Safari/537.36 Edg/98.0.1108.55\n",
			'content' => "" //body, payload
		)
	);
	
	//analysis_code
	//형태소 분석 (문어/구어) : "morp",
	//어휘의미 분석 (동음이의어 분석)(문어) : "wsd"
	//어휘의미 분석 (다의어 분석)(문어) : "wsd_poly"
	//개체명 인식 (문어/구어) : "ner"
	//의존 구문 분석 (문어) : "dparse"
	//의미역 인식 (문어) : "srl"
	const PAYLOAD = array(
		'request_id' => 'reserved field',
		'argument' => array(
			'text' => '',
			'analysis_code' => 'ner'
		)
	);
	
	const OPEN_API_MAX_SYL = 10000; //1만글자 제한
	//언어 분석 기술(문어)
	const OPEN_API_URL = "http://aiopen.etri.re.kr:8000/WiseNLU";
	//언어 분석 기술(구어)
	//const OPEN_API_URL = "http://aiopen.etri.re.kr:8000/WiseNLU_spoken";
	
	function etri_api($tx) {
		$op = HTTP_OP;
		$pl = PAYLOAD;
		$pl['argument']['text'] = $tx;
		$op['http']['content'] = json_encode($pl, JSON_UNESCAPED_UNICODE);
		$cx = stream_context_create($op);
		$fc = file_get_contents(OPEN_API_URL, 0, $cx);
		//var_dump($http_response_header);
		if($fc!==FALSE) {
			//$fc = gzdecode($fc); //Content-Encoding: gzip (response header)
			//echo "$fc\n";
			$js = json_decode($fc, TRUE);
			//var_dump($js);
			return $js;
		}
		return $fc;
	}
	
	function check_indexed(&$xa, &$mm) {
		foreach($xa as $x) {
			if(array_key_exists($x, $mm))
				return TRUE;
		}
		return FALSE;
	}
	
	function add_index($k, $t, &$wm, &$wl) {
		if(!array_key_exists($k, $wm)) {
			$wm[$k] = 1;
			$wl[$t] []= $k;
		}
		else $wm[$k]++;
	}
	
	//형태소 단위 색인
	function word_indexer_MO(&$st, &$wm, &$wl, &$mm) {
		$x = 0;
		foreach($st['morp'] as $mo) {
			$ty = $mo['type'];
			$lm = $mo['lemma'];
			//$id = $mo['id'];
			if($ty=='NNG'||$ty=='NNP'||$ty=='NR'||$ty=='SL'||$ty=='SN') {
				$xa = array($x);
				if(!check_indexed($xa, $mm)) {
					$k = $mo['lemma'];
					if($ty=='SL') $k = mb_strtoupper($k);
					add_index($k, 'MO', $wm, $wl);
					//marking morp used
					$mm[$x] = TRUE;
				}
			}
			$x++;
		}
	}
	
	//한 어절내 복합명사 색인
	function word_indexer_CN(&$st, &$wm, &$wl, &$mm) {
		foreach($st['word'] as $w) {
			//$id = $w['id'];
			$mb = $w['begin'];
			$me = $w['end'];
			$cn = [];
			$xa = [];
			for($x=$mb; $x<=$me; $x++) {
				$mo = $st['morp'][$x];
				$ty = $mo['type'];
				if($ty=='XPN'||$ty=='NNG'||$ty=='NNP'||$ty=='XSN'||$ty=='NR'||$ty=='SL'||$ty=='SN'||$ty=='NNB') {
					$ex = FALSE;
					//첫번째 형태소가 XSN,NNBC이면 제외
					if(($ty=='XSN'||$ty=='NNB')&&count($cn)==0) $ex = TRUE;
					if(!$ex) {
						$k = $mo['lemma'];
						if($ty=='SL') $k = mb_strtoupper($k);
						$cn []= $k;
						$xa []= $x;//$mo['id'];
					}
				}
				else {
					//형태소 2개이상
					if(count($cn)>=2) {
						$k = implode('', $cn);
						if(!check_indexed($xa, $mm)) {
							add_index($k, 'CN', $wm, $wl);
							//marking morp used
							foreach($xa as $z) $mm[$z] = TRUE;
						}
					}
					$cn = [];
					$xa = [];
				}
			}
			//remnant
			if(count($cn)>1) {
				$k = implode('', $cn);
				if(!check_indexed($xa, $mm)) {
					add_index($k, 'CN', $wm, $wl);
					//marking morp used
					foreach($xa as $z) $mm[$z] = TRUE;
				}
			}
		}
	}
	
	//NE 색인
	function word_indexer_NE(&$st, &$wm, &$wl, &$mm) {
		foreach($st['NE'] as $ne) {
			$k = $ne['text'];
			$b = $ne['begin'];
			$e = $ne['end'];
			add_index($k, 'NE', $wm, $wl);
			for($x=$b;$x<=$e;$x++) $mm[$x] = TRUE;
		}
	}
	
	function word_indexer(&$st, &$wm, &$wl) {
		$mm = [];
		//word_indexer_NE($st, $wm, $wl, $mm);
		word_indexer_CN($st, $wm, $wl, $mm);
		word_indexer_MO($st, $wm, $wl, $mm);
	}
	
	//start here
	//ini_set('log_errors', 1); //STDERR on
	//ini_set('display_errors', 0); //STDOUT off
	
	mb_internal_encoding('UTF-8');
	
	header('Content-Type: application/json; charset=UTF-8');
	
	//컨텐츠 타입이 JSON 인지 확인한다
	/*if(!in_array('application/json', explode(';',$_SERVER['CONTENT_TYPE']))){
		echo json_encode(array('result_code' => '400'));
		exit;
	}*/
	
	if(php_sapi_name()=="cli") $fc = file_get_contents('./payload.txt');
        //if(php_sapi_name()=="cli") $fc = file_get_contents('php://stdin');
	//else $fc = file_get_contents('php://input');
        else $fc = file_get_contents('./payload.txt');
	$js = json_decode($fc, TRUE);
	$tx = trim($js['text']);

	if($tx!=='') {
		$wm = [];
		$wl = [];
		$tx = mb_substr($tx, 0, OPEN_API_MAX_SYL);
		$ma = etri_api($tx);
		foreach($ma['return_object']['sentence'] as $st) {
			word_indexer($st, $wm, $wl);
		}
		arsort($wm, SORT_NUMERIC);
		//var_dump($wm);
		
		$ja = array(
			'result_code' => 200,
			'text_len' => mb_strlen($tx),
			'word_index' => $wm,
			'word_layer' => $wl
		);
	}
	else $ja = array('result_code' => 400);
	
	$js = json_encode($ja, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
	echo $js;
?>
