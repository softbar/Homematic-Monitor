<?php
trait HMScript {
	protected $ScrUrl='';
	
	protected function HMListDevices(){
		$script='object lD = dom.GetObject(ID_DEVICES);object oD; string sEnum; string sAdr;foreach(sEnum,lD.EnumIDs()){oD = lD.Get(sEnum);if(oD){ sAdr=oD.Address();if((sAdr != "") && (sAdr!="BidCoS-RF") && (sAdr.Length() < 12) ){WriteLine(oD.Address() #"|" #oD.Name().ToUTF8() );}}}';
		$lines = explode("\r\n", $this->HMRunScript($script));
		foreach($lines as $no=>$line){
			if(empty(trim($line))){
				$lines=array_splice($lines, 0,$no);
				break;
			}
			$l=explode('|',$line);
			if(count($l)<2 && strlen($l[0])>10){
 				unset($lines[$no]);
 				continue;
 			}
			while(count($l)<2)$l[]=null;
			$l=array_combine(['address','name'], $l);
			$lines[$no]=$l;				
		}
		return array_values($lines);
	}
	protected function HMGetDeviceNames(string $Address=''){
		$subDevice = strpos($Address,':')!==false;
		$includeSub= strpos($Address,':*')!==false;
		if($includeSub)$Address=substr($Address,0,-2);
		$script='object lD=dom.GetObject(ID_DEVICES);string sId;string sAddr;string cId;string sName;string sTmp;string gTmp;object oC;object oD;foreach(sId,lD.EnumIDs()){oD=dom.GetObject(sId);sName=oD.Name().Trim();sAddr=oD.Address().Trim();if((sAddr=="")||(sAddr=="BidCoS-RF")){ continue;}';
		if($Address){
			if(!$subDevice||$includeSub){
				$script.='if(sAddr!="'.$Address.'"){  continue; }';
				$script.='WriteLine(sAddr # "|" # sName.ToUTF8() # "|" # sId );';
				if(!$includeSub)$script.='break;';
			}
		} else $script.='WriteLine(sAddr # "|" # sName.ToUTF8() # "|" # sId );';
		$script.='foreach(cId, oD.Channels()) {
			oC = dom.GetObject(cId);
			gTmp=dom.GetObject(oC.ChnFunction());
			sTmp=dom.GetObject(oC.ChnRoom());';
		if($subDevice&&!$includeSub){
			$script.='if(oC.Address()!="'.$Address.'"){  continue; }';
			$script.='WriteLine(oC.Address() # "|" # oC.Name().ToUTF8() # "|" # cId # "|" # sTmp # "|" # gTmp );';
			$script.='break;';
		}
		$script.='WriteLine(oC.Address() # "|" # oC.Name().ToUTF8() # "|" # cId # "|" # sTmp # "|" # gTmp );}} WriteLine("");';
		$lines = explode("\r\n", $this->HMRunScript($script));
		foreach($lines as $no=>$line){
			if(empty(trim($line))){
				$lines=array_splice($lines, 0,$no);
				break;
			}
			$l=explode('|',$line);
			while(count($l)<5)$l[]=null;
			$l=array_combine(['address','name','id','room','group'], $l);
			$lines[$no]=$l;				
		}
		return count($lines)==1 ? $lines[0] : $lines;
	}
	protected function HMRunScript(string $HMScript, string $url='Test.exe', int $Port=8181){
	  	static $ch=null;
	  	if(empty($ch)){
			if(empty($this->ScrUrl)){
				if($this->ScrUrl=@$this->ReadAttributeString('ScriptUrl')){
					$this->Auth=$this->ReadAttributeString('AuthInfo');	
				}
		  		if(empty($this->ScrUrl)) {
			  		trigger_error('CCU Url/Host not set.', E_USER_NOTICE);
			  		return null;
			  	}
	        }
	  		
		  	$header[] = "Accept: text/plain,text/xml,application/xml,application/xhtml+xml,text/html";
			$header[] = "Cache-Control: max-age=0";
			$header[] = "Connection: close";
			$header[] = "Accept-Charset: UTF-8";
			$header[] = "Content-type: text/plain;charset=\"UTF-8\"";
	        $ch = curl_init($this->ScrUrl.$url);
	  		if(isset($this->Auth)&&!empty($this->Auth)){
				curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
				curl_setopt($ch, CURLOPT_USERPWD, $this->Auth);
			}
			curl_setopt($ch, CURLOPT_HEADER, false);
			curl_setopt($ch, CURLOPT_FAILONERROR, true);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:'));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 2000);
			curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
			curl_setopt($ch, CURLOPT_TIMEOUT_MS, 5000);
	  	}
		curl_setopt($ch, CURLOPT_POSTFIELDS, $HMScript);
		$result = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		$ch=null;
		if ($http_code >= 400) {
			trigger_error('CCU unreachable: ' . $http_code, E_USER_NOTICE);
			return null;
		}
		if ($result === false) {
			trigger_error('CCU unreachable', E_USER_NOTICE);
			return null;
		}
		return $result;
	}
}

?>