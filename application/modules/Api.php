<?php
defined('BASEPATH') OR exit('No direct script access allowed');


class Api extends CI_Controller
{
    function __construct()
    {
        parent::__construct();
        $this->load->model('Auth/User_model');
        $this->load->model('card/Card_model');
        $this->load->model('transaction/Transaction_model');
        $this->load->model('cardtype/Cardtype_model');
    }

    /*
     * cardCode : Mã thẻ cào
     * cardSeri : Seri thẻ cào
     * CardType : Loại thẻ ("VTT"- Viettel, "VNP" - Vina, "VMS" – Mobi)
     *
     * */
    /*
     * Trạng thái the
     * 0: pending
     * -1: Thẻ lỗi
     * 1: Nạp thẻ thành công
     * 2: Thẻ đang xử lý
     * 3: Thẻ đang xử lý. Liên hệ admin để check thủ công
     *
     * */

    /*
     * Signature  = md5(key+code+requestid)
     * */

    public function sendCard_v2_bk()
    {
        header('Content-Type: application/json');

        set_time_limit(0);
        $result = array('status' => -1, 'msg' => 'init');
        $key = $this->input->get('key', true);
        $Signature = $this->input->get('Signature', true);
        $cardCode = $this->input->get('cardCode', true);

        $cardSeri = $this->input->get('cardSeri', true);
        $isOK = true;
        $cardType = $this->input->get('cardType', true);
        $cardValue = $this->input->get('cardValue', true);
        $ckCard = $this->checkCard($cardSeri, $cardCode);

        if (isset($ckCard->id) && $ckCard->id > 0) {
            echo json_encode(array('status' => -1, 'msg' => 'Thẻ dã tồn tại trong hệ thống!'));
            die();
        }
        try {
            if (!($key)) {
                $isOK = false;
                throw new Exception('Key không để trống!');
            }
            $user = $this->User_model->find_by('key', $key);

            if (!($user->id)) {
                $isOK = false;
                throw new Exception('Key không chính xác hoặc tài khoản bị khóa!');
            }
            if (!($cardCode)) {
                $isOK = false;
                throw new Exception('Mã thẻ không hợp lệ!');
            }
            if (!($cardSeri)) {
                $isOK = false;
                throw new Exception('Seri thẻ không hợp lệ!');
            }
            $cardTypeInfo = $this->Card_model->getCardType($cardType);

            if (!($cardType) || !($cardTypeInfo->id)) {
                $isOK = false;
                throw new Exception('Loại thẻ [' . $cardType . '] không hợp lệ!');
                exit();
            }
            $ckCard = $this->checkCard($cardSeri, $cardCode);

            if (isset($ckCard->id) && $ckCard->id > 0) {
                echo json_encode(array('status' => -1, 'msg' => 'Thẻ dã tồn tại trong hệ thống!'));
                exit();
            }
            $cardValid = $this->validCard($cardType, $cardSeri, $cardCode);
            if ($cardValid['status'] == -1) {
                $isOK = false;
                throw new Exception($cardValid['msg']);
            }
            $my_Sign = md5($key . $cardCode . $cardSeri);
            if (!isset($Signature) || $Signature != $my_Sign) {
                $isOK = false;
                throw new Exception('Signature không hợp lệ!' . $my_Sign);
            }
            if ($isOK == false) {
                echo json_decode($result);
                die;
            }

            // Remove it
            if ($user->id < 10) {
                if (in_array($cardType, array('VTT', 'VMS', 'VNP'))) {
                    $this->Card_model->checkCustomerStatus($cardSeri, $cardType, $cardCode, $cardValue);
                    echo json_encode(array('status' => -1, 'msg' => 'Loại thẻ [' . $cardType . '] đang bảo trì'));
                    die;
                }
                $this->Card_model->checkCustomerStatus($cardSeri, $cardType, $cardCode, $cardValue);
            } else {
                if (in_array($cardType, array('VTT', 'VMS', 'VNP'))) {
                    echo json_encode(array('status' => -1, 'msg' => 'Loại thẻ [' . $cardType . '] đang bảo trì'));
                    die;
                }
            }
            // end ..
            $requestId = $user->id . date('Ymdhis') . rand();
            $cardCode = str_replace(' ', '', $cardCode);
            $cardCode = str_replace('-', '', $cardCode);
            $cardSeri = str_replace(' ', '', $cardSeri);
            $cardSeri = str_replace('-', '', $cardSeri);
            $arrSave = array(
                'cardcode' => $cardCode,
                'cardseri' => $cardSeri,
                'cardtype' => $cardType,
                'cardvalue' => $cardValue,
                'realvalue' => 0,
                'request_id' => $requestId,
                'user_id' => $user->id,
                'status' => 0,
                'rate' => $cardTypeInfo->discount,
                'date_created' => date('Y-m-d'),
                'api' => 'sendCard_v2'

            );

            $cardInsertId = $this->Card_model->add($arrSave);


            $keyAPI = 'fdb22287762c5f8067b8d8132d4f8064';
            $arrSend = array(

                'KeyAPI' => $keyAPI,
                'TypeCard' => $cardType,
                'CodeCard' => $cardCode,
                'SeriCard' => $cardSeri,
                'ValueCard' => $cardValue,
                'IDRequest' => $requestId,
                'Signature' => md5($keyAPI . $cardValue . $requestId)
            );

            if ($cardType == 'ZING') {
                $arrSend['card_id'] = $cardInsertId;
                $cardInfo = $this->sendChargeRequestZing($arrSend);
                echo json_encode($cardInfo);
                exit();
            }
            if ($cardType == 'GARENA') {
                $arrSend['card_id'] = $cardInsertId;
                $cardInfo = $this->sendChargeGarena($arrSend);
                echo json_encode($cardInfo);
                exit();
            }

            $cardInfo = $this->sendToTelco($arrSend);
            $cardInfo = json_decode($cardInfo);
            if (isset($cardInfo->Codes)) {
                if ($cardInfo->Codes == 999) {
                    $this->Card_model->update($cardInsertId, array('status' => 2, 'note' => 'Thẻ đang xử lý!', 'responsed' => json_encode($cardInfo)));
                    $result['status'] = 2;
                    $result['msg'] = ' Thẻ đang xử lý. ';
                } else {
                    $result['status'] = -1;
                    $result['msg'] = $cardInfo->Mes;
                    $this->Card_model->update($cardInsertId, array('status' => -1, 'note' => 'Thẻ sai hoặc đã được sử dụng', 'responsed' => json_encode($cardInfo)));
                }
            }


        } catch (Exception $e) {
            $result['msg'] = $e->getMessage();
        }
        if (isset($requestId)) {
            $result['transaction_id'] = $requestId;
        }
        echo json_encode($result);
        die;
    }

    private function checkCard($seri, $code, $type)
    {
        return $this->Card_model->checkCard($seri, $code, $type);
    }

    private function validCard($type, $seri, $code)
    {
        $result['status'] = 1;
        $result['msg'] = 'ok';
        $numSeri = strlen($seri);
        $numCode = strlen($code);
        if ($type == 'VTT') {
            if ($numSeri < 10 || $numCode < 12) {
                $result['status'] = -102;
                $result['msg'] = 'Mã thẻ hoặc Seri thẻ Viettel không hợp lệ';
            }
        }
        if ($type == 'VNP') {
            if ($numSeri < 13 || $numCode < 13) {
                $result['status'] = -102;
                $result['msg'] = 'Mã thẻ hoặc Seri thẻ Vinaphone không hợp lệ';
            }
        }
        if ($type == 'VMS') {
            if ($numSeri < 15 || $numCode < 12) {
                $result['status'] = -102;
                $result['msg'] = 'Mã thẻ hoặc Seri thẻ Mobifone không hợp lệ';
            }
        }
        if ($type == 'ZING') {
            if ($numSeri < 12 || $numCode < 9) {
                $result['status'] = -102;
                $result['msg'] = 'Mã thẻ hoặc Seri thẻ Zing không hợp lệ';
            }
        }
        if ($type == 'GARENA') {
            if ($numSeri < 9 || $numCode < 16) {
                $result['status'] = -102;
                $result['msg'] = 'Mã thẻ hoặc Seri thẻ Garena không hợp lệ';
            }
        }
        return $result;
    }

    private function sendChargeRequestZing($input)
    {
        $input['ValueCard'] = 10000;
        $cardTypeInfo = $this->Card_model->getCardType($input['TypeCard'], $input['ValueCard']);

        $card_id = $input['card_id'];
        $seri = $input['SeriCard'];
        $pin = $input['CodeCard'];
        $requestid = $input['IDRequest'];
        $accountName = $cardTypeInfo->zing_account;
        $cookie = $cardTypeInfo->zing_cookie;
        $cardInfo = $this->Card_model->find_by('request_id', $requestid);
        $userInfo = $this->User_model->find_by('id', $cardInfo->user_id);
        $data = $cardTypeInfo->config . '&cardSerial=' . urlencode($seri) . '&cardPassword=' . urlencode($pin) . '&accountName=' . $accountName;
        $result = $this->post_data(
            'https://new.pay.zing.vn/ajax/payment-zingcard',
            $data, $cookie, null
        );

        $trans = json_decode($result, false, 512, JSON_BIGINT_AS_STRING);
        $value = 0;
        $message = 'Hãy kiểm tra lại thông tin thẻ';
        $message_color = 'red';

        if ($trans->transID) {
            $data = 'transID=' . $trans->transID;
            sleep(3);
            $result = $this->post_data(
                'https://new.pay.zing.vn/ajax/get-result',
                $data, $cookie, 'https://pay.zing.vn/payment/' . $cardTypeInfo->zing_game . '/' . $trans->transID
            );
            $trans = json_decode($result, false, 512, JSON_BIGINT_AS_STRING);
            $trans->grossValue = str_replace(',', '', $trans->grossValue);
            $trans->netValue = str_replace(',', '', $trans->netValue);
            if ($trans->grossValue > 0) {
                $value = str_replace(',', '', $trans->grossValue);
                $value = intval($value);
                $trans->value = $value;

                $tranferNote = 'Bạn đã nạp thẻ ' . number_format($trans->grossValue) . 'đ. seri ' . $requestid;
                // Tính tiền
                $cardreceive = $trans->netValue;
                $moneyAdd = $cardreceive - (($cardreceive * $cardTypeInfo->discount) / 100);
                $moneyAfterChange = $userInfo->balance + $moneyAdd;
                // ket  thuc tính tiền
                $arrCallback = array('cardvalue' => $trans->netValue, 'status' => 1, 'note' => 'Nạp thẻ thành công!', 'callback_note' => 'Nạp thẻ thành công', 'realvalue' => $trans->netValue, 'receivevalue' => $cardreceive, 'money_after_rate' => $moneyAdd);
                $this->Card_model->update($input['card_id'], $arrCallback);


                $arrTransAdd = array(
                    'user_id' => $cardInfo->user_id,
                    'money_card' => $trans->netValue,
                    'money_add' => $moneyAdd,
                    'money' => $moneyAdd,
                    'before_change' => $userInfo->balance,
                    'after_change' => $moneyAfterChange,
                    'status' => 1,
                    'note' => $tranferNote
                );
                $this->Transaction_model->insert($arrTransAdd);
                $this->User_model->update($userInfo->id, array('balance' => $moneyAfterChange));
                $arrSendToCustomer = array(
                    'status' => 1,
                    'value' => $trans->netValue,
                    'real_value' => $trans->netValue,
                    'received_value' => $moneyAdd,
                    'msg' => 'Nạp thẻ thành công',
                    'transaction_id' => $requestid,
                    'returnCode' => 1,
                    'returnMessage' => 'Nạp thẻ thành công'
                );
                return $arrSendToCustomer;
            } else {
                $arrCallback = array('status' => -1, 'note' => 'Nạp thẻ thất bại', 'callback_note' => 'Thẻ sai hoặc đã được sử dụng', 'realvalue' => 0, 'receivevalue' => 0);
                $this->Card_model->update($input['card_id'], $arrCallback);
                return array('status' => -1, 'returnCode' => -1, 'msg' => $trans->returnMessage, 'returnMessage' => $trans->returnMessage, 'transaction_id' => $requestid);
            }
        } else {
            $arrCallback = array('status' => -1, 'note' => 'Nạp thẻ thất bại', 'callback_note' => 'Thẻ sai hoặc đã được sử dụng', 'realvalue' => 0, 'receivevalue' => 0);
            $this->Card_model->update($input['card_id'], $arrCallback);
            return (array('status' => -1, 'returnCode' => -1, 'msg' => 'Thẻ sai', 'transaction_id' => $requestid, 'returnMessage' => 'Thẻ sai hoặc đã được sử dụng'));
        }

        return $trans;
    }

    /*
     *     keyapi        : Key bên đối tác cung cấp không nên sử dụng key API bên TienIch gửi qua
    requestid     : Mã giao dịch hệ thống đầu thẻ gọi sang để xử lý thẻ (giá trị này sẽ là duy nhất)
    status        : Trạng thái kết quả của giao dịch (0: Thất bại, 1: Thành công, -1: Lỗi khác được mô trả trong notes)
    realvalue     : Gía trị thực tế của thẻ khi nhà mạng trả về mặc định =0 chỉ khi thành công mới có giá trị>0
    datasign      : MD5(keyapi+requestid) chuỗi xác thực giao dịch
    notes         : Thông thông tin ghi chú khi giao dịch thẻ được xử lý với nhà mạng.
     * */

    function post_data($site, $data, $cookie, $referer)
    {
        $datapost = curl_init();
        curl_setopt($datapost, CURLOPT_URL, $site);
        curl_setopt($datapost, CURLOPT_TIMEOUT, 40000);

        curl_setopt($datapost, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($datapost, CURLOPT_POST, true);
        curl_setopt($datapost, CURLOPT_POSTFIELDS, $data);
        curl_setopt($datapost, CURLOPT_COOKIE, $cookie);
        if ($referer != null) {
            curl_setopt($datapost, CURLOPT_REFERER, $referer);
        }
        ob_start();
        return curl_exec($datapost);
        ob_end_clean();
        curl_close($datapost);
        unset($datapost);
    }

    private function sendChargeGarena($input)
    {
        set_time_limit(500);
        //partnerCode + serviceCode + commandCode+ requestContent + partnerKey
        $partnerCode = 'ken';
        $partnerKey = 'fe41de265291d91d56e16c6ea2328ce0';
        $serviceCode = 'cardtelco';
        $commandCode = 'usecard';

        //         $card_id = $input['card_id'];
        $seri = $input['SeriCard'];
        $pin = $input['CodeCard'];
        $value = $input['ValueCard'];
        $cardType = ($input['TypeCard'] == 'VTT') ? 'viettel' : $input['TypeCard'];

        $requestid = $input['IDRequest'];
        $requestContent = array(
            'CardSerial' => $seri,
            'CardCode' => $pin,
            'CardType' => strtolower($cardType),
            'AccountName' => $partnerCode,
            'RefCode' => $requestid,
            'AmountUser' => $value,
            'CallbackUrl' => 'https://khoainuong.info/card/callbackcms'
        );
        $requestContent = json_encode($requestContent);
        $arr = array(
            'partnerCode' => 'ken',
            'serviceCode' => 'cardtelco',
            'commandCode' => 'usecard',
            'requestContent' => $requestContent,
            'signature' => md5($partnerCode . $serviceCode . $commandCode . $requestContent . $partnerKey)
        );

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://apicard.flutepal.info/VPGJsonService.ashx",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($arr),
            CURLOPT_HTTPHEADER => array(
                "Accept: */*",
                "Content-Type: application/json"
            ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);
        $response = json_decode($response);
        if ($response->ResponseCode != 1 && $response->ResponseCode != -372) {
            $arrCallback = array('status' => -1, 'note' => 'Nạp thẻ thất bại', 'callback_note' => 'Thẻ sai hoặc đã được sử dụng', 'realvalue' => 0, 'receivevalue' => 0, 'responsed' => json_encode($response));
            $this->Card_model->update($input['card_id'], $arrCallback);
            return array('status' => -1, 'returnCode' => -1, 'reason' => $response->ResponseCode, 'returnMessage' => 'Thẻ sai hoặc đã được sử dụng', 'msg' => 'Thẻ sai hoặc đã được sử dụng', 'transaction_id' => $requestid);
        } else {
            $cardInfo = $this->Card_model->find_by('request_id', $requestid);
            $userInfo = $this->User_model->find_by('id', $cardInfo->user_id);
            $cardTypeInfo = $this->Card_model->getCardType($input['TypeCard'], $input['ValueCard']);
            $tranferNote = 'Bạn đã nạp thẻ ' . number_format($response->ResponseContent) . 'đ. Seri:<strong>' . $seri . '</strong> - code: <strong>' . $pin . '</strong>';
            // Tính tiền
            $cardreceive = $response->ResponseContent;
            if ($input['ValueCard'] < $response->ResponseContent) {
                $cardreceive = $input['ValueCard'];
            }
            if (in_array($input['TypeCard'], array('ZING', 'GARENA'))) {
                $cardreceive = $response->ResponseContent;
            }

            $moneyAdd = $cardreceive - (($cardreceive * $cardTypeInfo->discount) / 100);
            $moneyAfterChange = $userInfo->balance + $moneyAdd;
            // ket  thuc tính tiền
            $arrCallback = array('status' => 1, 'note' => 'Nạp thẻ thành công!', 'callback_note' => 'Nạp thẻ thành công', 'realvalue' => $response->ResponseContent, 'receivevalue' => $cardreceive, 'money_after_rate' => $moneyAdd, 'responsed' => json_encode($response));

            $this->Card_model->update($input['card_id'], $arrCallback);

            // Thêm giao dich:transaction
            $arrTransAdd = array(
                'user_id' => $cardInfo->user_id,
                'money_card' => $response->ResponseContent,
                'money_add' => $moneyAdd,
                'money' => $moneyAdd,
                'before_change' => $userInfo->balance,
                'after_change' => $moneyAfterChange,
                'status' => 1,
                'note' => $tranferNote
            );
            $this->Transaction_model->insert($arrTransAdd);
            $this->User_model->update($userInfo->id, array('balance' => $moneyAfterChange));
            $arrSendToCustomer = array(
                'status' => 1,
                'value' => $cardInfo->cardvalue,
                'real_value' => $response->ResponseContent,
                'received_value' => $cardreceive,
                'msg' => 'Nạp thẻ thành công',
                'transaction_id' => $requestid,
                'returnCode' => 1,
                'returnMessage' => 'Nạp thẻ thành công'

            );

            return $arrSendToCustomer;
        }


        //   return json_encode($response);

    }

    public function curlToThumuathe($cardInfo)
    {
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://apicard.thumuathe.shop/VPGJsonService.ashx",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($cardInfo),
            CURLOPT_HTTPHEADER => array(
                "Accept: */*",
                "Content-Type: application/json"
            ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
        return $response;
    }

    private function sendToTelco($data)
    {
        return json_encode(array('status' => -1, 'msg' => 'false'));
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://telco247.info/apivs2/api_addcardhc",
            //CURLOPT_URL => "http://tienich.us/apivs2/api_addcardhc",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => array(
                "Accept: */*",
                "Accept-Encoding: gzip, deflate",
                "Cache-Control: no-cache",
                "Connection: keep-alive",
                "Content-Type: application/x-www-form-urlencoded",
                "Host: telco247.info"
            ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            return $err;
        } else {
            return $response;
        }
    }

    public function sendCard()
    {
        header('Content-Type: application/json');

        $result = array('status' => 0, 'msg' => 'init');
        $key = $this->input->post('key', true);
        $Signature = $this->input->post('Signature', true);
        $cardCode = $this->input->post('cardCode', true);
        $cardSeri = $this->input->post('cardSeri', true);
        $cardType = $this->input->post('cardType', true);
        $cardValue = $this->input->post('cardValue', true);
        try {
            if ($key == '') {
                throw new Exception('Key không hợp lệ!');
            }
            $user = $this->User_model->find_by('key', $key);
            if (!isset($user->id)) {
                throw new Exception('Key không hợp lệ!');
            }
            if (!isset($cardCode)) {
                throw new Exception('Mã thẻ không hợp lệ!');
            }
            if (!isset($cardSeri)) {
                throw new Exception('Seri thẻ không hợp lệ!');
            }
            $cardTypeInfo = $this->Card_model->getCardType($cardType);

            if (!isset($cardType) || !isset($cardTypeInfo->id)) {
                throw new Exception('Loại thẻ [' . $cardType . '] không hợp lệ!');
            }
            $ckCard = $this->checkCard($cardSeri, $cardCode);

            if (isset($ckCard->id)) {

                // $this->Card_model->update($cardInsertId, array('status' => -1, 'note' => 'Thẻ sai hoặc đã sử dụng!'));
                throw new Exception('[' . $ckCard->id . ']Thẻ đã tồn tại trong hệ thống!');
            }
            $my_Sign = md5($key . $cardCode . $cardSeri);
            if (!isset($Signature) || $Signature != $my_Sign) {
                throw new Exception('Signature không hợp lệ!' . $my_Sign);
            }
            $cardCode = str_replace(' ', '', $cardCode);
            $cardCode = str_replace('-', '', $cardCode);
            $cardSeri = str_replace(' ', '', $cardSeri);
            $cardSeri = str_replace('-', '', $cardSeri);
            $requestId = $user->id . date('Ymdhis');
            $arrSave = array(
                'cardcode' => $cardCode,
                'cardseri' => $cardSeri,
                'cardtype' => $cardType,
                'cardvalue' => $cardValue,
                'realvalue' => 0,
                'request_id' => $requestId,
                'user_id' => $user->id,
                'status' => 0,
                'rate' => $cardTypeInfo->discount,
                'date_created' => date('Y-m-d'),
                'api' => 'sendCard'
            );

            $cardInsertId = $this->Card_model->add($arrSave);


            $keyAPI = 'fdb22287762c5f8067b8d8132d4f8064';
            $arrSend = array(
                'KeyAPI' => $keyAPI,
                'TypeCard' => $cardType,
                'CodeCard' => $cardCode,
                'SeriCard' => $cardSeri,
                'ValueCard' => $cardValue,
                'IDRequest' => $requestId,
                'Signature' => md5($keyAPI . $cardValue . $requestId)
            );
            if ($user->id < 10) {
                $this->Card_model->checkCustomerStatus($cardSeri, $cardType, $cardCode, $cardValue);
            }
            $cardInfo = $this->sendToTelco($arrSend);
            $cardInfo = json_decode($cardInfo);
            if (isset($cardInfo->Codes)) {
                if ($cardInfo->Codes == 999) {
                    $this->Card_model->update($cardInsertId, array('status' => 2, 'note' => 'Thẻ đang xử lý!', 'responsed' => json_encode($cardInfo)));
                    $result['status'] = 2;
                    $result['msg'] = ' Thẻ đang xử lý. ';
                } else {
                    $result['status'] = -1;
                    $result['msg'] = $cardInfo->Mes;
                    $this->Card_model->update($cardInsertId, array('status' => -1, 'note' => 'Thẻ sai hoặc đã được sử dụng', 'responsed' => json_encode($cardInfo)));
                }
            }


        } catch (Exception $e) {
            $result['msg'] = $e->getMessage();
        }
        echo json_encode($result);
        die;
    }

    public function callback()
    {
        header('Content-Type: application/json');
        $json = file_get_contents('php://input');
        $json = json_decode($json);

        $keyapi = $json->keyapi;
        $requestid = $json->requestid;
        $realvalue = $json->realvalue;
        $datasign = $json->datasign;
        $notes = $json->notes;
        $status = (int)$json->status;
        $result['Codes'] = 0;
        $result['Msg'] = 'Thất bại';
        $allData = $this->input->post();
        $this->db->insert('callback_logs', array('content' => json_encode($json)));
        try {
            if (!isset($keyapi)) {
                throw new Exception('Key API is empty!');
            }
            if (!isset($requestid)) {
                throw new Exception('Request is empty!');
            }
            if (!isset($realvalue)) {
                throw new Exception('Real Value is empty!');
            }
            $cardInfo = $this->Card_model->find_by('request_id', $requestid);
            $userInfo = $this->User_model->find_by('id', $cardInfo->user_id);
            if ($status == 1) {


                if (isset($cardInfo->status) && $cardInfo->status == 2) {

                    // add transaction

                    $cardType = $this->Card_model->getCardType($cardInfo->cardtype);
                    $tranferNote = 'Bạn đã nạp thẻ ' . $cardType->name . ': ' . number_format($realvalue) . 'đ. mã GD ' . $requestid;
                    // Tính tiền
                    $cardreceive = $realvalue;
                    if ($realvalue < $cardInfo->cardvalue) {
                        $cardreceive = $realvalue * 0.5;
                        $tranferNote = 'Bạn đã nạp thẻ ' . $cardType->name . ': ' . number_format($realvalue) . 'đ.Sai mệnh giá phạt 50% mệnh giá thực. mã GD ' . $requestid;
                    }
                    if ($realvalue > $cardInfo->cardvalue) {
                        $cardreceive = $cardInfo->cardvalue * 0.5;
                        $tranferNote = 'Bạn đã nạp thẻ ' . $cardType->name . ': ' . number_format($realvalue) . 'đ.Sai mệnh giá phạt 50% mệnh giá gửi. mã GD ' . $requestid;
                    }

                    $moneyAdd = $cardreceive - (($cardreceive * $cardType->discount) / 100);
                    $moneyAfterChange = $userInfo->balance + $moneyAdd;

                    // Kết thúc tính tiền
                    $arrCallback = array('status' => 1, 'money_after_rate' => $moneyAdd, 'note' => 'Nạp thẻ thành công!', 'callback_note' => $notes, 'realvalue' => $realvalue, 'receivevalue' => $cardreceive);
                    $this->Card_model->update($cardInfo->id, $arrCallback);


                    $arrTransAdd = array(
                        'user_id' => $cardInfo->user_id,
                        'money_card' => $realvalue,
                        'money_add' => $moneyAdd,
                        'money' => $moneyAdd,
                        'before_change' => $userInfo->balance,
                        'after_change' => $moneyAfterChange,
                        'status' => 1,
                        'note' => $tranferNote
                    );

                    $this->Transaction_model->insert($arrTransAdd);
                    $this->User_model->update($userInfo->id, array('balance' => $moneyAfterChange));
                    // end add transaction
                    $arrSendToCustomer = array(
                        'status' => 1,
                        'value' => $cardInfo->cardvalue,
                        'real_value' => $realvalue,
                        'received_value' => $moneyAdd,
                        'transaction_id' => $requestid,
                        'card_seri' => $cardInfo->cardseri,
                        'card_code' => $cardInfo->cardcode
                    );
                    // Callback to Customer

                    if (isset($userInfo->callback_url)) {
                        // $this->sentToCustomer($arrSendToCustomer, $userInfo->callback_url);
                        @file_get_contents($userInfo->callback_url . '?' . http_build_query($arrSendToCustomer));
                    }

                    $result['Codes'] = 1;
                    $result['Msg'] = 'Thành công';
                    //
                }
            } else {
                $cardInfoByRequest = $this->Card_model->find_by('request_id', $requestid);
                $arrCallback = array('status' => -1, 'note' => $notes, 'callback_note' => $notes, 'realvalue' => 0, 'receivevalue' => 0);
                $this->Card_model->update($cardInfoByRequest->id, $arrCallback);
                // Callback to Customer
                $arrSendToCustomer = array(
                    'status' => -1,
                    'value' => 0,
                    'real_value' => 0,
                    'received_value' => 0,
                    'transaction_id' => $requestid,
                    'card_seri' => $cardInfo->cardseri,
                    'card_code' => $cardInfo->cardcode
                );
                // Callback to Customer
                $result['urlCustomerCallback'] = $userInfo->callback_url;
                if (isset($userInfo->callback_url)) {
                    // $this->sentToCustomer($arrSendToCustomer, $userInfo->callback_url);
                    $a = file_get_contents($userInfo->callback_url . '?' . http_build_query($arrSendToCustomer));
                    $result['aaa'] = $userInfo->callback_url . '?' . http_build_query($arrSendToCustomer);

                }
                // end CallBack to Customer
                throw new Exception('Nạp thẻ thất bại!');
            }
        } catch (Exception $e) {
            $result['Msg'] = $e->getMessage();
        }
        echo json_encode($result);
        die;
    }

    public function sendCard_tt()
    {
        set_time_limit(0);
        header('Content-Type: application/json');

        $result = array('status' => 0, 'msg' => 'init');
        $key = $this->input->get('key', true);
        $Signature = $this->input->get('Signature', true);
        $cardCode = $this->input->get('cardCode', true);
        $cardSeri = $this->input->get('cardSeri', true);
        $cardType = $this->input->get('cardType', true);
        $cardValue = $this->input->get('cardValue', true);
        $ckCard = $this->checkCard($cardSeri, $cardCode);

        if (isset($ckCard->id) && $ckCard->id > 0) {
            echo json_encode(array('status' => -1, 'msg' => 'Thẻ dã tồn tại trong hệ thống!'));
            exit();
        }
        try {
            if (!isset($key)) {
                throw new Exception('Key không để trống!');
            }
            $user = $this->User_model->find_by('key', $key);

            if (!isset($user->id)) {
                throw new Exception('Key không chính xác hoặc tài khoản bị khóa!');
            }
            if (!isset($cardCode)) {
                throw new Exception('Mã thẻ không hợp lệ!');
            }
            if (!isset($cardSeri)) {
                throw new Exception('Seri thẻ không hợp lệ!');
            }
            $cardTypeInfo = $this->Card_model->getCardType($cardType);

            if (!isset($cardType) || !isset($cardTypeInfo->id)) {
                throw new Exception('Loại thẻ [' . $cardType . '] không hợp lệ!');
            }
            if ($cardType == 'GARENA' && $user->allow_garena == 0) {
                echo json_encode(array('status' => -1, 'msg' => 'Loại thẻ [' . $cardType . '] không hợp lệ!'));
                die();
            }
            $my_Sign = md5($key . $cardCode . $cardSeri);
            if (!isset($Signature) || $Signature != $my_Sign) {
                throw new Exception('Signature không hợp lệ!' . $my_Sign);
            }
            $ckCard = $this->checkCard($cardSeri, $cardCode);

            if (isset($ckCard->id)) {
                // $this->Card_model->update($cardInsertId, array('status' => -1, 'note' => 'Thẻ sai hoặc đã sử dụng!'));
                throw new Exception('[' . $ckCard->id . ']Thẻ đã tồn tại trong hệ thống!');
            }

            if (in_array($cardType, array('VTT', 'VMS', 'VNP'))) {
                //     echo json_encode(array('status' => -1, 'msg' => 'Loại thẻ [' . $cardType . '] đang bảo trì'));
                //    die;
            }


            $cardCode = str_replace(' ', '', $cardCode);
            $cardCode = str_replace('-', '', $cardCode);
            $cardSeri = str_replace(' ', '', $cardSeri);
            $cardSeri = str_replace('-', '', $cardSeri);
            $requestId = $user->id . date('Ymdhis') . rand();
            $arrSave = array(
                'cardcode' => $cardCode,
                'cardseri' => $cardSeri,
                'cardtype' => $cardType,
                'cardvalue' => $cardValue,
                'realvalue' => 0,
                'request_id' => $requestId,
                'user_id' => $user->id,
                'status' => 0,
                'rate' => $cardTypeInfo->discount,
                'date_created' => date('Y-m-d'),
                'api' => 'sendCard_tt',
                'request_header' => json_encode($_SERVER)
            );

            $cardInsertId = $this->Card_model->add($arrSave);


            $keyAPI = 'fdb22287762c5f8067b8d8132d4f8064';
            if ($cardType == 'VTT') $cardType = 'viettel';
            $arrSend = array(

                'KeyAPI' => $keyAPI,
                'TypeCard' => $cardType,
                'CodeCard' => $cardCode,
                'SeriCard' => $cardSeri,
                'ValueCard' => $cardValue,
                'IDRequest' => $requestId,
                'Signature' => md5($keyAPI . $cardValue . $requestId),
                'card_id' => $cardInsertId,
                'transaction_id' => $requestId
            );
            if ($cardType == 'VTT') $cardType = 'viettel';
            if ($cardType == 'ZING') {
                $arrSend['card_id'] = $cardInsertId;
                $cardInfo = $this->sendChargeGarena($arrSend);
                echo json_encode($cardInfo);
                exit();
            }

            if ($cardType == 'GARENA') {
                $arrSend['card_id'] = $cardInsertId;
                $cardInfo = $this->sendChargeGarena($arrSend);
                echo json_encode($cardInfo);
                exit();
            }

            $cardInfo = $this->sendChargeGarena($arrSend);
            echo $cardInfo = json_decode($cardInfo);
            die;
            // Waitting callback
            if ($cardInfo['status'] == 2) {
                $i = 0;
                $arrSendToCustomer = array(
                    'status' => 2,
                    'msg' => 'Thẻ đang xử lý ...'
                );
                $chk = false;
                while ($i < 20 && $chk == false) {
                    sleep(2);
                    $cardFinal = $this->Card_model->find_by('id', $cardInsertId);
                    if ($cardFinal->status != 2) {
                        $chk = true;
                        $moneyAdd = $cardFinal->receivevalue - (($cardFinal->receivevalue * $cardTypeInfo->discount) / 100);
                        $arrSendToCustomer = array(
                            'status' => $cardFinal->status,
                            'value' => $cardFinal->cardvalue,
                            'real_value' => $cardFinal->realvalue,
                            'received_value' => $moneyAdd,
                            'msg' => ($cardFinal->status == 1) ? 'Nạp thẻ thành công' : 'Nạp thẻ thất bại'
                        );
                        break;
                    }
                    $i++;
                }
                $result = $arrSendToCustomer;
            } else {
                $result['status'] = -1;
                $result['msg'] = $cardInfo->Mes;
                $this->Card_model->update($cardInsertId, array('status' => -1, 'note' => 'Thẻ sai hoặc đã được sử dụng', 'responsed' => json_encode($cardInfo)));
            }

            // end waitting callback
            /*
                        if (isset($cardInfo->Codes)) {
                            if ($cardInfo->Codes == 999) {
                                $this->Card_model->update($cardInsertId, array('status' => 2, 'note' => 'Thẻ đang xử lý!', 'responsed' => json_encode($cardInfo)));
                                $i = 0;
                                $arrSendToCustomer = array(
                                    'status' => 2,
                                    'msg' => 'Thẻ đang xử lý'
                                );
                                $chk = false;
                                while ($i < 20 && $chk == false) {
                                    sleep(2);
                                    $cardFinal = $this->Card_model->find_by('id', $cardInsertId);
                                    if ($cardFinal->status != 2) {
                                        $chk = true;
                                        $moneyAdd = $cardFinal->receivevalue - (($cardFinal->receivevalue * $cardTypeInfo->discount) / 100);
                                        $arrSendToCustomer = array(
                                            'status' => $cardFinal->status,
                                            'value' => $cardFinal->cardvalue,
                                            'real_value' => $cardFinal->realvalue,
                                            'received_value' => $moneyAdd,
                                            'msg' => ($cardFinal->status == 1) ? 'Nạp thẻ thành công' : 'Nạp thẻ thất bại'
                                        );
                                        break;
                                    }
                                    $i++;
                                }
                                $result = $arrSendToCustomer;
                            } else {
                                $result['status'] = -1;
                                $result['msg'] = $cardInfo->Mes;
                                $this->Card_model->update($cardInsertId, array('status' => -1, 'note' => 'Thẻ sai hoặc đã được sử dụng', 'responsed' => json_encode($cardInfo)));
                            }
                        }*/


        } catch (Exception $e) {
            $result['msg'] = $e->getMessage();
        }
        if (isset($requestId)) {
            $result['transaction_id'] = $requestId;
        }
        echo json_encode($result);
        die;
    }

    public function sendCard_zing()
    {
        header('Content-Type: application/json');

        $result = array('status' => -1, 'msg' => 'init', 'returnCode' => -1, 'returnMessage' => '');
        $key = $this->input->post('key', true);
        $Signature = $this->input->post('Signature', true);
        $cardCode = $this->input->post('cardCode', true);

        $cardSeri = $this->input->post('cardSeri', true);

        $cardType = $this->input->post('cardType', true);
        $cardValue = $this->input->post('cardValue', true);
        try {
            if (!isset($key)) {
                throw new Exception('Key không để trống!');
            }
            $user = $this->User_model->find_by('key', $key);

            if (!isset($user->id)) {
                $result['returnMessage'] = 'Key không chính xác hoặc tài khoản bị khóa';
                throw new Exception('Key không chính xác hoặc tài khoản bị khóa!');
            }
            if (!isset($cardCode)) {
                $result['returnMessage'] = 'Mã thẻ không hợp lệ!';
                throw new Exception('Mã thẻ không hợp lệ!');
            }
            if (!isset($cardSeri)) {
                $result['returnMessage'] = 'Seri thẻ không hợp lệ!';
                throw new Exception('Seri thẻ không hợp lệ!');
            }
            $cardTypeInfo = $this->Card_model->getCardType($cardType);

            if (!isset($cardType) || !isset($cardTypeInfo->id) || $cardType != 'ZING') {
                $result['returnMessage'] = 'Loại thẻ [' . $cardType . '] không hợp lệ!';
                throw new Exception('Loại thẻ [' . $cardType . '] không hợp lệ!');
            }

            $my_Sign = md5($key . $cardCode . $cardSeri);
            if (!isset($Signature) || $Signature != $my_Sign) {
                $result['returnMessage'] = 'Signature không hợp lệ!';
                throw new Exception('Signature không hợp lệ!' . $my_Sign);
            }

            $requestId = $user->id . date('Ymdhis') . rand();
            $ckCard = $this->checkCard($cardSeri, $cardCode);

            if (isset($ckCard->id)) {
                // $this->Card_model->update($cardInsertId, array('status' => -1, 'note' => 'Thẻ sai hoặc đã sử dụng!'));
                $result['returnMessage'] = '[' . $ckCard->id . ']Thẻ đã tồn tại trong hệ thống!';
                throw new Exception('[' . $ckCard->id . ']Thẻ đã tồn tại trong hệ thống!');
            }
            $cardCode = str_replace(' ', '', $cardCode);
            $cardCode = str_replace('-', '', $cardCode);
            $cardSeri = str_replace(' ', '', $cardSeri);
            $cardSeri = str_replace('-', '', $cardSeri);
            $arrSave = array(
                'cardcode' => $cardCode,
                'cardseri' => $cardSeri,
                'cardtype' => $cardType,
                'cardvalue' => $cardValue,
                'rate' => $cardTypeInfo->discount,
                'realvalue' => 0,
                'request_id' => $requestId,
                'user_id' => $user->id,
                'status' => 0,
                'date_created' => date('Y-m-d'),
                'api' => 'sendCard_zing',
                'request_header' => json_encode($_SERVER)
            );

            $cardInsertId = $this->Card_model->add($arrSave);


            $keyAPI = 'fdb22287762c5f8067b8d8132d4f8064';
            $arrSend = array(

                'KeyAPI' => $keyAPI,
                'TypeCard' => $cardType,
                'CodeCard' => $cardCode,
                'SeriCard' => $cardSeri,
                'ValueCard' => $cardValue,
                'IDRequest' => $requestId,
                'Signature' => md5($keyAPI . $cardValue . $requestId)
            );


            $arrSend['card_id'] = $cardInsertId;
            $cardInfo = $this->sendChargeGarena($arrSend);
            echo json_encode($cardInfo);
            exit();

        } catch (Exception $e) {
            $result['msg'] = $e->getMessage();
        }
        if (isset($requestId)) {
            $result['transaction_id'] = $requestId;
        }
        echo json_encode($result);
        die;
    }

    public function sendCard_v2()
    {

        header('Content-Type: application/json');

        set_time_limit(120);
        $result = array('status' => -1, 'msg' => 'init');
        $key = $this->input->get('key', true);
        $Signature = $this->input->get('Signature', true);
        $cardCode = $this->input->get('cardCode', true);
        $cardCode = trim($cardCode);
        $cardSeri = $this->input->get('cardSeri', true);
        $cardSeri = trim($cardSeri);
        $isOK = true;
        $cardType = $this->input->get('cardType', true);
        $cardValue = $this->input->get('cardValue', true);
        $ckCard = $this->checkCard($cardSeri, $cardCode, $cardType);

        if ($ckCard == 1) {
            echo json_encode(array('status' => -1, 'msg' => 'Thẻ đã tồn tại trong hệ thống!'));
            die();
        }
        try {
            if (!($key)) {
                $isOK = false;
                throw new Exception('Key không để trống!');
            }
            $user = $this->User_model->find_by('key', $key);

            if (!($user->id)) {
                $isOK = false;
                throw new Exception('Key không chính xác hoặc tài khoản bị khóa!');
            }
            if (!($cardCode)) {
                $isOK = false;
                throw new Exception('Mã thẻ không hợp lệ!');
            }
            if (!($cardSeri)) {
                $isOK = false;
                throw new Exception('Seri thẻ không hợp lệ!');
            }
            $cardTypeInfo = $this->Card_model->getCardType($cardType);

            if (!($cardType) || !isset($cardTypeInfo->id)) {
                $isOK = false;
                throw new Exception('Loại thẻ [' . $cardType . '] không hợp lệ!');
                exit();
            }

            if ($cardTypeInfo->status == 0) {
                echo json_encode(array('status' => -1, 'msg' => 'Loại thẻ [' . $cardType . '] Đang bảo trì!.'));
                die();
            }
            if ($cardType == 'GARENA' && $user->allow_garena == 0) {
                echo json_encode(array('status' => -1, 'msg' => 'Loại thẻ [' . $cardType . '] không hợp lệ!'));
                die();
            }
            $cardValid = $this->validCard($cardType, $cardSeri, $cardCode);
            if ($cardValid['status'] == -1) {
                $isOK = false;
                throw new Exception($cardValid['msg']);
            }
            $my_Sign = md5($key . $cardCode . $cardSeri);
            if (!isset($Signature) || $Signature != $my_Sign) {
                $isOK = false;
                throw new Exception('Signature không hợp lệ!' . $my_Sign);
            }

            if ($isOK == false) {
                echo json_decode($result);
                die;
            }


            // end ..
            $requestId = $user->id . date('Ymdhis') . rand();
            $cardCode = str_replace(' ', '', $cardCode);
            $cardCode = str_replace('-', '', $cardCode);
            $cardSeri = str_replace(' ', '', $cardSeri);
            $cardSeri = str_replace('-', '', $cardSeri);
            $arrSave = array(
                'cardcode' => $cardCode,
                'cardseri' => $cardSeri,
                'cardtype' => $cardType,
                'cardvalue' => $cardValue,
                'realvalue' => 0,
                'request_id' => $requestId,
                'user_id' => $user->id,
                'status' => 0,
                'rate' => $cardTypeInfo->discount,
                'date_created' => date('Y-m-d'),
                'api' => 'sendCard_v2',
                'request_header' => json_encode($_SERVER)

            );

            $cardInsertId = $this->Card_model->add($arrSave);


            $keyAPI = 'fdb22287762c5f8067b8d8132d4f8064';
            $arrSend = array(

                'KeyAPI' => $keyAPI,
                'TypeCard' => $cardType,
                'CodeCard' => $cardCode,
                'SeriCard' => $cardSeri,
                'ValueCard' => $cardValue,
                'IDRequest' => $requestId,
                'Signature' => md5($keyAPI . $cardValue . $requestId),
                'card_id' => $cardInsertId,
                'transaction_id' => $requestId
            );

            if ($cardType == 'ZING') {
                $cardInfo = $this->sendChargeGarena($arrSend);
                echo json_encode($cardInfo);
                exit();
            }
            if ($cardType == 'GARENA') {
                $cardInfo = $this->sendChargeGarena($arrSend);
                echo json_encode($cardInfo);
                exit();
            }
            //       if (($user->id < 10 || $user->id == 71) && in_array($cardType, array('VTT', 'VNP', 'VMS'))) {
            if ($cardType == 'VTT') $cardType = 'viettel';

            $arrSend['TypeCard'] = $cardType;
            $cardInfo = $this->sendChargeGarena($arrSend);
            echo json_encode($cardInfo);
            exit();
            //    }
            $cardInfo = $this->sendToNaptienGa($arrSend);
            echo json_encode($cardInfo);
            die;

        } catch (Exception $e) {
            $result['msg'] = $e->getMessage();
        }
        if (isset($requestId)) {
            $result['transaction_id'] = $requestId;
        }
        echo json_encode($result);
        die;
    }


    public function reCallTocms($cardId)
    {
        $cardInfo = $this->Card_model->find_by('id', $cardId);
        if (!in_array($cardInfo->status, array(-1, -2, 0))) {
            die('responsed not null');
        }
        $this->Card_model->update($cardInfo->id,array('partner'=>'cms'));
        $keyAPI = 'fdb22287762c5f8067b8d8132d4f8064';
        $arrSend = array(

            'KeyAPI' => $keyAPI,
            'TypeCard' => $cardInfo->cardtype,
            'CodeCard' => $cardInfo->cardcode,
            'SeriCard' => $cardInfo->cardseri,
            'ValueCard' => $cardInfo->cardvalue,
            'IDRequest' => $cardInfo->request_id,
            'Signature' => md5($keyAPI . $cardInfo->cardvalue . $cardInfo->request_id)
        );

        $arrSend['card_id'] = $cardInfo->id;

        $response = $this->sendChargeGarena($arrSend);


        $userInfo = $this->User_model->find_by('id', $cardInfo->user_id);

        if (isset($userInfo->callback_url) && $userInfo->callback_url != '') {

            if ($response['status'] == 1) {
                $arrSendToCustomer = array(
                    'status' => 1,
                    'value' => $cardInfo->cardvalue,
                    'real_value' => $response['real_value'],
                    'received_value' => $response['received_value'],
                    'transaction_id' => $cardInfo->request_id,
                    'card_seri' => $cardInfo->cardseri,
                    'card_code' => $cardInfo->cardcode,
                    'refcode' => $cardInfo->refcode
                );

            } else {
                $arrSendToCustomer = array(
                    'status' => -1,
                    'value' => $cardInfo->cardvalue,
                    'real_value' => 0,
                    'received_value' => 0,
                    'transaction_id' => $cardInfo->request_id,
                    'card_seri' => $cardInfo->cardseri,
                    'card_code' => $cardInfo->cardcode,
                     'refcode' => $cardInfo->refcode
                );
            }
            if (isset($response['reason'])) {
                if ($response['reason'] == -326 || $response['reason'] == '-326' || $response['reason'] == -328) {
                    die('-326');
                }
            }
            if ($cardInfo->responsed != 'null') {
                $curl = curl_init();
                curl_setopt_array($curl, array(
                    CURLOPT_RETURNTRANSFER => 0,
                    CURLOPT_URL => $userInfo->callback_url . '?' . http_build_query($arrSendToCustomer),
                    CURLOPT_USERAGENT => 'doithe',
                    CURLOPT_SSL_VERIFYPEER => false
                ));

                $resp = curl_exec($curl);
                $http_info = curl_getinfo($curl);
                // End
                curl_close($curl);
                $this->db->insert('callback_sends', array('responsed' => json_encode($resp), 'http_code' => $http_info['http_code'], 'http_info' => json_encode($http_info), 'card_id' => $cardInfo->id, 'url' => $userInfo->callback_url, 'data' => json_encode($arrSendToCustomer), 'created_on' => date('Y-m-d H:i:s')));
                die;;
            }

        }

        echo '<br/>' . json_encode($response);

        die;

    }

    public function sendCallBackToC3Tek($cardId)
    {

        $cardInfo = $this->Card_model->find_by('id', $cardId);
        if ($cardInfo->user_id != 45) {
            die('not 45');
        }
        /*
         *  - status: 1 => Gạch thẻ thành công
               -1 => Thẻ sai hoặc đã sử dụng
         - card_seri: seri thẻ
         - card_code: mã thẻ
         - value: Giá trị thẻ gửi sang
         - real_value: Giá trị thực của thẻ
         - received_value: Giá trị thẻ chốt
         - transaction_id : Mã giao dịch
        */
        $c3tek_url = 'http://45.124.94.225/apis/partners/callback';

        $partner_token_api = 'YzUyYjMwMTdhNTViMTQ0M2ZlZjVjNzkwMTRmNzE3ZDU=';
        $partner_name = 'doanthanh';

        $param['status'] = $cardInfo->status;
        $param['value'] = $cardInfo->cardvalue;
        $param['card_seri'] = $cardInfo->cardseri;
        $param['card_code'] = $cardInfo->cardcode;
        $param['real_value'] = $cardInfo->realvalue;
        $param['received_value'] = $cardInfo->receivevalue;
        $param['transaction_id'] = $cardInfo->request_id;
        $param['partner_token_api'] = $partner_token_api;
        $param['partner_name'] = $partner_name;
        $param['partner_signature'] = md5($partner_token_api . $partner_name);


        $ch = curl_init($c3tek_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $param);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
        die;
    }

    private function sentToCustomer($data, $urlCallback)
    {
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $urlCallback,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => array(
                "Accept: */*",
                "Accept-Encoding: gzip, deflate",
                "Cache-Control: no-cache",
                "Connection: keep-alive",
                "Content-Type: application/x-www-form-urlencoded"
            ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            return $err;
        } else {
            return $response;
        }
    }


    public function cronjob()
    {
        $cards = $this->Card_model->getCardRecallCronjob();

        $i = 0;
        if (isset($cards[0])) {
            foreach ($cards as $item) {
                if ($item->date_created > '2020-03-22') {
                    $i++;

                    if ($i == 1) {
                        //    print_r($item);
                        $this->Card_model->update($item->id, array('responsed' => 'recall'));
                        $this->reCallTocms($item->id);

                    }
                }
            }
        }
    }
 
    public function sendCard_v3()
    {
        header('Content-Type: application/json');

        set_time_limit(120);
        $result = array('status' => -1, 'msg' => 'init');
        $key = $this->input->get('key', true);
        $Signature = ($this->input->get('Signature', true))?$this->input->get('Signature', true):$this->input->get('signature', true);
        $cardCode = $this->input->get('cardCode', true);
        $cardCode = trim($cardCode);
        $cardSeri = $this->input->get('cardSeri', true);
        $cardSeri = trim($cardSeri);
        $isOK = true;
        $cardType = $this->input->get('cardType', true);
        $cardValue = $this->input->get('cardValue', true);

        $refcode = $this->input->get('refcode', true);
        //    $ckCard = $this->checkCard($cardSeri, $cardCode, $cardType);


        try {
            if (!($key)) {
                $isOK = false;
                throw new Exception('Key không để trống!');
            }
            $user = $this->User_model->find_by('key', $key);
 
            if (!($user->id)) {
                $isOK = false;
                throw new Exception('Key không chính xác hoặc tài khoản bị khóa!');
            }
            $chkCardExits = $this->Card_model->checkcardexists($cardSeri, $cardCode, $cardType);
            if (isset($chkCardExits->id)) {
                if ($chkCardExits->user_id == $user->id) {
                    $result['status'] = 1;
                    $result['msg'] = 'Nhận thẻ thành công!. retry';
                    $result['transaction_id'] = $chkCardExits->request_id;
                    echo json_encode($result);
                    die;
                } else {
                    $result['status'] = -103;
                    $result['msg'] = 'Thẻ đã tồn tại trong hệ thống!.';

                    echo json_encode($result);
                    die;
                }
            }
            if (!($cardCode)) {
                $isOK = false;
                $result['status'] = -104;
                throw new Exception('Mã thẻ không hợp lệ!');
            }
            if (!($cardSeri)) {
                $isOK = false;
                $result['status'] = -104;
                throw new Exception('Seri thẻ không hợp lệ!');
            }
            $cardTypeInfo = $this->Card_model->getCardType($cardType);

            if (!($cardType) || !isset($cardTypeInfo->id)) {
                $isOK = false;
                $result['status'] = -105;
                throw new Exception('Loại thẻ [' . $cardType . '] không hợp lệ!');
                exit();
            }

            if ($cardTypeInfo->status == 0) {
                echo json_encode(array('status' => -101, 'msg' => 'Loại thẻ [' . $cardType . '] Đang bảo trì!.'));
                die();
            }
            if ($cardType == 'GARENA' && $user->allow_garena == 0) {
                echo json_encode(array('status' => -101, 'msg' => 'Loại thẻ [' . $cardType . '] không hợp lệ!'));
                die();
            }
            $cardValid = $this->validCard($cardType, $cardSeri, $cardCode);
            if ($cardValid['status'] == -1) {
                $isOK = false;
                throw new Exception($cardValid['msg']);
            }
            $my_Sign = md5($key . $cardCode . $cardSeri);
            if (!isset($Signature) || $Signature != $my_Sign) {
                $isOK = false;
                $result['status'] = -106;
                throw new Exception('Signature không hợp lệ!' . $my_Sign);
            }


            if ($isOK == false) {
                echo json_decode($result);
                die;
            }


            // end ..
            $requestId = 'K' . $user->id . date('Ymdhis') . rand();
            $cardCode = str_replace(' ', '', $cardCode);
            $cardCode = str_replace('-', '', $cardCode);
            $cardSeri = str_replace(' ', '', $cardSeri);
            $cardSeri = str_replace('-', '', $cardSeri);
            $arrSave = array(
                'cardcode' => $cardCode,
                'cardseri' => $cardSeri,
                'cardtype' => $cardType,
                'cardvalue' => $cardValue,
                'realvalue' => 0,
                'request_id' => $requestId,
                'user_id' => $user->id,
                'status' => -2,
                'rate' => $cardTypeInfo->discount,
                'date_created' => date('Y-m-d'),
                'api' => 'sendCard_v3',
                'request_header' => json_encode($_SERVER),
                'refcode' => $refcode

            );
            $gateSDTVN = $this->checkGate($cardType, $cardValue, 'sdtvn');
              if ($gateSDTVN == true) {
                $arrSave['status'] = 0;
            }


            $cardInsertId = $this->Card_model->add($arrSave);
           if ($gateSDTVN == true) {
                $a = $this->sendToSDT($cardInsertId);
                echo json_encode($a);
                die;
            } 
            $result['status'] = 1;
            $result['msg'] = 'Nhận thẻ thành công!';
            $result['transaction_id'] = $requestId;

        } catch (Exception $e) {
            $result['msg'] = $e->getMessage();
        }
        echo json_encode($result);
        die;
    }

    public function checkstatus()
    {
        header('Content-Type: application/json');
        $transaction_id = $this->input->get('transaction_id', true);
        $signature = $this->input->get('signature', true);
        $result['status'] = -1;
        $result['msg'] = 'init';
        try {
            if (!$transaction_id) {
                throw new Exception('Transaction empty!');
            }
            if (!$signature) {
                throw new Exception('Signature empty!');
            }
            $cardInfo = $this->Card_model->find_by('request_id', $transaction_id);
            if (!isset($cardInfo->id)) {
                throw new Exception('transaction does not exist!');
            }
            $userInfo = $this->User_model->find_by('id', $cardInfo->user_id);

            $mysign = md5($cardInfo->request_id . $userInfo->key);
            if ($mysign != $signature) {
                throw new Exception('Signature does not exist!');
            }
            $arrSendToCustomer = array(
                'status' => $cardInfo->status,
                'value' => $cardInfo->cardvalue,
                'real_value' => $cardInfo->realvalue,
                'received_value' => $cardInfo->receivevalue,
                'transaction_id' => $cardInfo->request_id,
                'card_seri' => $cardInfo->cardseri,
                'card_code' => $cardInfo->cardcode
            );
            $result['msg'] = 'success';
            $result['status'] = 1;
            $result['data'] = $arrSendToCustomer;
        } catch (Exception $e) {
            $result['msg'] = $e->getMessage();
        }
        echo json_encode($result);
        die;
    }

    public function cronjob_v3()
    {
        header('Content-Type: application/json');
        /*        $file = 'people.txt';
                $current = file_get_contents($file);
                $current .= date('H:i:s').' | ';
                file_put_contents($file, $current);*/

        $cards = $this->Card_model->getCardForCronjobV3();
        $i = 0;
        foreach ($cards as $item) {
            $i++;
            if ($i < 3) {
                $this->Card_model->update($item->id, array('status' => 0));
                $this->reCallTocms($item->id);
            }
        }
    }

    public function sendToSimBank($cardId)
    {
        $cardInfo = $this->Card_model->find_by('id', $cardId);
        $userInfo = $this->User_model->find_by('id', $cardInfo->user_id);
        //{{ApiUrl}}/api/SIM/RegCharge?apiKey={{apiKey}}&code={{mã_thẻ}}&serial={{serial}}&type={{loại_thẻ}}&menhGia={{menhGia}}&requestId={{requestId}}
        $apiKey = '6d8dbc6f-8288-42ca-b6be-7a5a913e0efe';
        $apiUrl = 'http://abc.shopdoithe.vn/info.php';

        switch ($cardInfo->cardtype) {
            case "VTT":
                $type = 'vt';
                break;
            case "VNP":
                $type = 'vn';
                break;
            case "VMS":
                $type = 'mb';
                break;
            default :
                $type = 'error';
                break;
        }
        $param = array(
            'apiKey' => $apiKey,
            'code' => $cardInfo->cardcode,
            'serial' => $cardInfo->cardseri,
            'type' => $type,
            'menhGia' => $cardInfo->cardvalue,
            'requestId' => $cardInfo->request_id
        );
        $fullUrl = $apiUrl . '?' . http_build_query($param);
        //  echo $fullUrl;die;
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $fullUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/80.0.3987.149 Safari/537.36',
            CURLOPT_FAILONERROR => true
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
        $curlInfo = curl_getinfo($curl);
        $responsed = json_decode($response);
        if ($responsed->stt == 0) {
            $this->Card_model->update($cardInfo->id, array('status' => -1, 'responsed' => $response));
            $result['status'] = -1;
            $result['msg'] = 'Thông tin thẻ bị sai!';
            $result['transaction_id'] = $cardInfo->request_id;
            return $result;
        }
        $result['status'] = 1;
        $result['msg'] = 'Nhận thẻ thành công!';
        $result['transaction_id'] = $cardInfo->request_id;
        return $result;


    }

    public function callback_simbank()
    {
        header('Content-Type: application/json');

        $requestId = $this->input->get('requestId', true);
        $status = $this->input->get('status', true);
        $cardInfo = $this->Card_model->find_by('request_id', $requestId);
        $userInfo = $this->User_model->find_by('id', $cardInfo->user_id);
        $menhGiaThe = $this->input->get('menhGiaThe', true);
        $menhGiaThuc = $this->input->get('menhGiaThuc', true);

        if ($status != 'success') {
            $arrCallback = array('status' => -1, 'note' => 'Nạp thẻ thất bại', 'callback_note' => 'Thẻ sai hoặc đã được sử dụng', 'realvalue' => 0, 'receivevalue' => 0, 'responsed' => json_encode($this->input->get()));
            $this->Card_model->update($cardInfo->id, $arrCallback);
            $arrSendToCustomer = array(
                'status' => -1,
                'value' => $cardInfo->cardvalue,
                'real_value' => 0,
                'received_value' => 0,
                'transaction_id' => $cardInfo->request_id,
                'card_seri' => $cardInfo->cardseri,
                'card_code' => $cardInfo->cardcode,
                'refcode' => $cardInfo->refcode
            );
        } else {
            $cardTypeInfo = $this->Card_model->getCardType($cardInfo->cardtype, $menhGiaThuc);
            $tranferNote = 'Bạn đã nạp thẻ ' . number_format($menhGiaThuc) . 'đ. Seri:<strong>' . $cardInfo->cardseri . '</strong> - code: <strong>' . $cardInfo->cardcode . '</strong>';
            // Tính tiền
            $cardreceive = $menhGiaThuc;
            if ($menhGiaThe < $menhGiaThuc) {
                $cardreceive = $menhGiaThe;
            }

            $moneyAdd = $cardreceive - (($cardreceive * $cardTypeInfo->discount) / 100);
            $moneyAfterChange = $userInfo->balance + $moneyAdd;
            // ket  thuc tính tiền
            $arrCallback = array('status' => 1, 'note' => 'Nạp thẻ thành công!', 'callback_note' => 'Nạp thẻ thành công', 'realvalue' => $menhGiaThuc, 'receivevalue' => $cardreceive, 'money_after_rate' => $moneyAdd, 'responsed' => json_encode($this->input->get()));

            $this->Card_model->update($cardInfo->id, $arrCallback);

            // Thêm giao dich:transaction
            $arrTransAdd = array(
                'user_id' => $cardInfo->user_id,
                'money_card' => $menhGiaThe,
                'money_add' => $moneyAdd,
                'money' => $moneyAdd,
                'before_change' => $userInfo->balance,
                'after_change' => $moneyAfterChange,
                'status' => 1,
                'note' => $tranferNote
            );
            $this->Transaction_model->insert($arrTransAdd);
            $this->User_model->update($userInfo->id, array('balance' => $moneyAfterChange));
            $arrSendToCustomer = array(
                'status' => 1,
                'value' => $menhGiaThe,
                'real_value' => $menhGiaThuc,
                'received_value' => $cardreceive,
                'msg' => 'Nạp thẻ thành công',
                'transaction_id' => $requestId,
                'returnCode' => 1,
                'returnMessage' => 'Nạp thẻ thành công',
                'card_seri' => $cardInfo->cardseri,
                'card_code' => $cardInfo->cardcode,
                'refcode' => $cardInfo->refcode

            );
        }
        if (isset($userInfo->callback_url)) {
            $a = @file_get_contents($userInfo->callback_url . '?' . http_build_query($arrSendToCustomer));
        }
        echo json_encode(array('status' => 1));
        die;
    }

    public function sendToSDT($cardId)
    {
        $cardInfo = $this->Card_model->find_by('id', $cardId);
        $userInfo = $this->User_model->find_by('id', $cardInfo->user_id);
        //{{ApiUrl}}/api/SIM/RegCharge?apiKey={{apiKey}}&code={{mã_thẻ}}&serial={{serial}}&type={{loại_thẻ}}&menhGia={{menhGia}}&requestId={{requestId}}
        $apiKey = '9ac575c9-8eb6-4747-b684-1c672d0af8be';
        $apiUrl = 'http://ctv.shopdoithe.vn:10001/api/Card/RegCharge';
        $this->Card_model->update($cardInfo->id,array('partner'=>'sdtvn'));
        switch ($cardInfo->cardtype) {
            case "VTT":
                $type = 'vt';
                break;
            case "VNP":
                $type = 'vn';
                break;
            case "VMS":
                $type = 'mb';
                break;
            default :
                $type = 'error';
                break;
        }
        $param = array(
            'apiKey' => $apiKey,
            'code' => $cardInfo->cardcode,
            'serial' => $cardInfo->cardseri,
            'type' => $type,
            'menhGia' => $cardInfo->cardvalue,
            'requestId' => $cardInfo->request_id
        );
        $fullUrl = $apiUrl . '?' . http_build_query($param);

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $fullUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_TIMEOUT => 100,
            CURLOPT_CONNECTTIMEOUT => 100,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/80.0.3987.149 Safari/537.36',
            CURLOPT_FAILONERROR => true
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
        $curlInfo = curl_getinfo($curl);
        $responsed = json_decode($response);
 
        if ($responsed->stt == 0) {
            $this->Card_model->update($cardInfo->id, array('status' => -2));
            $result['status'] = 1;
            $result['msg'] = 'Nhận thẻ thành công!';
            $result['transaction_id'] = $cardInfo->request_id;
            return $result;
        }
        if ($responsed->stt == 2) {

            $this->Card_model->update($cardInfo->id, array('status' => -2));
            $result['status'] = 1;
            $result['msg'] = 'Nhận thẻ thành công!';
            $result['transaction_id'] = $cardInfo->request_id;
            return $result;
        }

        $result['status'] = 1;
        $result['msg'] = 'Nhận thẻ thành công!';
        $result['transaction_id'] = $cardInfo->request_id;
        return $result;
    }

    public function callback_sdtvn()
    {
        header('Content-Type: application/json');

        $requestId = $this->input->get('requestId', true);
        if (!$requestId) {
            echo json_encode(array('status' => -1, 'msg' => 'request không hợp lệ'));
            die;
        }

        $status = $this->input->get('status', true);
        $cardInfo = $this->Card_model->find_by('request_id', $requestId);
        if(!isset($cardInfo->id)){
            die('2');
        }
        $userInfo = $this->User_model->find_by('id', $cardInfo->user_id);
        $menhGiaThe = $this->input->get('menhGiaThe', true);
        $menhGiaThuc = $this->input->get('menhGiaThuc', true);
        if ($status == 'refund') {
            $this->Card_model->update($cardInfo->id, array('status' => -2));die;
        }
        if ($status != 'success') {
            $arrCallback = array('status' => -1, 'note' => 'Nạp thẻ thất bại', 'callback_note' => 'Thẻ sai hoặc đã được sử dụng', 'realvalue' => 0, 'receivevalue' => 0, 'responsed' => json_encode($this->input->get()));
            $this->Card_model->update($cardInfo->id, $arrCallback);
            $arrSendToCustomer = array(
                'status' => -1,
                'value' => $cardInfo->cardvalue,
                'real_value' => 0,
                'received_value' => 0,
                'transaction_id' => $cardInfo->request_id,
                'card_seri' => $cardInfo->cardseri,
                'card_code' => $cardInfo->cardcode,
                'refcode' => $cardInfo->refcode
            );
        } else {
            $cardTypeInfo = $this->Card_model->getCardType($cardInfo->cardtype, $menhGiaThuc);
            $tranferNote = 'Bạn đã nạp thẻ ' . number_format($menhGiaThuc) . 'đ. Seri:<strong>' . $cardInfo->cardseri . '</strong> - code: <strong>' . $cardInfo->cardcode . '</strong>';
            // Tính tiền
            $cardreceive = $menhGiaThuc;
            if ($menhGiaThe < $menhGiaThuc) {
                $cardreceive = $menhGiaThe;
            }

            $moneyAdd = $cardreceive - (($cardreceive * $cardTypeInfo->discount) / 100);
            $moneyAfterChange = $userInfo->balance + $moneyAdd;
            // ket  thuc tính tiền
            $arrCallback = array('status' => 1, 'note' => 'Nạp thẻ thành công!', 'callback_note' => 'Nạp thẻ thành công', 'realvalue' => $menhGiaThuc, 'receivevalue' => $cardreceive, 'money_after_rate' => $moneyAdd, 'responsed' => json_encode($this->input->get()));

            $this->Card_model->update($cardInfo->id, $arrCallback);

            // Thêm giao dich:transaction
            $arrTransAdd = array(
                'user_id' => $cardInfo->user_id,
                'money_card' => $menhGiaThe,
                'money_add' => $moneyAdd,
                'money' => $moneyAdd,
                'before_change' => $userInfo->balance,
                'after_change' => $moneyAfterChange,
                'status' => 1,
                'note' => $tranferNote
            );
            $this->Transaction_model->insert($arrTransAdd);
            $this->User_model->update($userInfo->id, array('balance' => $moneyAfterChange));
            $arrSendToCustomer = array(
                'status' => 1,
                'value' => $menhGiaThe,
                'real_value' => $menhGiaThuc,
                'received_value' => $cardreceive,
                'msg' => 'Nạp thẻ thành công',
                'transaction_id' => $requestId,
                'returnCode' => 1,
                'returnMessage' => 'Nạp thẻ thành công',
                'card_seri' => $cardInfo->cardseri,
                'card_code' => $cardInfo->cardcode,
                'refcode' => $cardInfo->refcode

            );
        }

        $allDataSaveCallback = $this->input->get();
        $this->db->insert('callback_logs', array('content' => json_encode($allDataSaveCallback)));

        if (isset($userInfo->callback_url)) {
        //    $a = @file_get_contents($userInfo->callback_url . '?' . http_build_query($arrSendToCustomer));
       /*
        * Curl
        * */
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_URL => $userInfo->callback_url . '?' . http_build_query($arrSendToCustomer),
                CURLOPT_USERAGENT => 'XuanThuLab test cURL Request',
                CURLOPT_SSL_VERIFYPEER => false
            ));

            $resp = curl_exec($curl);
            $http_info = curl_getinfo ($curl);
            // End
            curl_close($curl);
            $this->db->insert('callback_sends', array('responsed' => json_encode($resp), 'http_code'=>$http_info['http_code'],'http_info'=>json_encode($http_info), 'card_id' => $cardInfo->id, 'url' => $userInfo->callback_url, 'data' => json_encode($arrSendToCustomer), 'created_on' => date('Y-m-d H:i:s')));


        }
        echo json_encode(array('status' => 1));
        die;
    }


    public function checkGate($type, $money, $gate)
    {
        $check = $this->Cardtype_model->checkGate($type, $money, $gate);
        if (isset($check->id)) {
            return true;
        }
        return false;
    }

}