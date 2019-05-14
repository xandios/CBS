<?php

/******************************************************************************/
/******************************************************************************/

class CBSGoogleCalendar
{
	
	/**************************************************************************/
	
	function __construct()
	{
		$this->token=get_option(PLUGIN_CBS_CONTEXT.'_google_calendar_token','');
		$this->expiration=get_option(PLUGIN_CBS_CONTEXT.'_google_calendar_expiration','');
		$this->settings=json_decode(get_option(PLUGIN_CBS_CONTEXT.'_google_calendar_settings',''));
	}
	
	/**************************************************************************/
	
	function insertBooking($bookingId)
	{
		$token=$this->getToken();
		if(!$token)return(false);
		
		if(!($bookingId>0))return(false);
		
		$Booking=new CBSBooking();
		$Location=new CBSLocation();
		
		$booking=$Booking->getBooking($bookingId);
		if(!count($booking))return(false);
		
		$location_id=$booking['meta']['location_id'];		
		$location=$Location->getDictionary(array
        (
            'location_id'														=> $location_id,
		));
        
		if(!count($booking))return(false);
		
		$Timezone=new DateTimeZone($this->getTimezoneString());
		
        $start=$booking['meta']['date'].' '.$booking['meta']['time'];
		$startDate=new DateTime($start,$Timezone);
		
        $endDate=clone $startDate;
		$endDate->modify('+'.$booking['meta']['duration'].' minutes');
		
        $bookingDescription=sprintf(__('<a href="%s" target="_blank">%s</a><br>Client: %s %s',PLUGIN_CBS_DOMAIN),admin_url('post.php').'?post='.$bookingId.'&action=edit',$booking['post']->post_title,$booking['meta']['client_first_name'],$booking['meta']['client_second_name']);
		
		$bookingDetails=array
        (
			'summary'															=>  $booking['post']->post_title,
			'description'														=>  $bookingDescription,
			'location'															=>  $booking['meta']['location_name'],
			'start'                                                             =>  array
            (
				'dateTime'														=>  $startDate->format(DateTime::RFC3339),
			),
			'end'                                                               =>  array
            (
				'dateTime'														=>  $endDate->format(DateTime::RFC3339),
			),
		);

		$ch=curl_init();
		
        curl_setopt($ch,CURLOPT_URL,'https://www.googleapis.com/calendar/v3/calendars/'.$location[$location_id]['meta']['google_calendar_id'].'/events?access_token='.$token);
		curl_setopt($ch,CURLOPT_POST,1);
		curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($bookingDetails));
		curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,0);
		curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,0);
		curl_setopt($ch,CURLOPT_HTTPHEADER,array('Content-Type: application/json')); 
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
		
        $response=curl_exec($ch);
		$responseDecoded=json_decode($response);
		curl_close($ch);
		
		if((is_object($responseDecoded)) && (property_exists($responseDecoded,'kind')) && ($responseDecoded->kind=='calendar#event')) return(true);
		
        return(false);
	}
	
	/**************************************************************************/
	
	function getCalendarList()
	{
		$token=$this->getToken();
		if(!$token)return(false);
		
		$ch=curl_init();
        
		curl_setopt($ch,CURLOPT_URL,'https://www.googleapis.com/calendar/v3/users/me/calendarList?access_token='.$token);
		curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,0);
		curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,0);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
        
		$response=curl_exec($ch);
		$responseDecoded=json_decode($response);
        
		curl_close($ch);
		
		if(is_object($responseDecoded) && property_exists($responseDecoded,'kind') && $responseDecoded->kind=='calendar#calendarList') return($responseDecoded);
        return(false);
	}
	
	/**************************************************************************/
	
	function getToken()
	{
		if(is_null($this->settings))
			return(false);
		
		if($this->token && $this->expiration && $this->expiration>time())
			return($this->token);
		
		$header='{"alg":"RS256","typ":"JWT"}';
		$headerEncoded=$this->base64urlEncode($header);
		
		$assertionTime=time();
		$expirationTime=$assertionTime+3600;
		$claimSet='{
		  "iss":"'.$this->settings->client_email.'",
		  "scope":"https://www.googleapis.com/auth/calendar",
		  "aud":"https://www.googleapis.com/oauth2/v4/token",
		  "exp":'.$expirationTime.',
		  "iat":'.$assertionTime.'
		}';
		$claimSetEncoded=$this->base64urlEncode($claimSet);

		$signature='';
		openssl_sign($headerEncoded.'.'.$claimSetEncoded,$signature,$this->settings->private_key,'SHA256');
		$signatureEncoded=$this->base64urlEncode($signature);
		$assertion=$headerEncoded.'.'.$claimSetEncoded.'.'.$signatureEncoded;

		$ch=curl_init();
        
		curl_setopt($ch,CURLOPT_URL,'https://www.googleapis.com/oauth2/v4/token');
		curl_setopt($ch,CURLOPT_POST,1);
		curl_setopt($ch,CURLOPT_HTTPHEADER,array('Content-Type: application/x-www-form-urlencoded'));
		curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,0);
		curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,0);
		curl_setopt($ch,CURLOPT_POSTFIELDS,'grant_type=urn%3Aietf%3Aparams%3Aoauth%3Agrant-type%3Ajwt-bearer&assertion='.$assertion);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
        
		$response=curl_exec($ch);
		$responseDecoded=json_decode($response);
        
		curl_close($ch);
		
		if(is_object($responseDecoded) && property_exists($responseDecoded,'access_token'))
		{
			$this->token=$responseDecoded->access_token;
			$this->expiration=$expirationTime;
			update_option(PLUGIN_CBS_CONTEXT.'_google_calendar_token',$responseDecoded->access_token);
			update_option(PLUGIN_CBS_CONTEXT.'_google_calendar_expiration',$expirationTime);
			return($responseDecoded->access_token);
		}
	
        return(false);
	}
	
	/**************************************************************************/
	
	function base64urlEncode($data)
	{
		return(rtrim(strtr(base64_encode($data),'+/','-_'),'='));
	}
	
	/**************************************************************************/

    function getTimezoneString()
    {
        $timezoneString=get_option('timezone_string');
        if(!$timezoneString)
        {
            $gmtOffset=get_option('gmt_offset');
            $timezoneString=timezone_name_from_abbr('',$gmtOffset*3600,false);
            if($timezoneString===false)
                $timezoneString=timezone_name_from_abbr('',0,false);
        }
        return($timezoneString);        
    }

	/**************************************************************************/

}

/******************************************************************************/
/******************************************************************************/