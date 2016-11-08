<?php
/**
 * Created by PhpStorm.
 * User: 谢亚岚
 * Date: 2016/10/29
 * Time: 15:33
 */

namespace Home\Controller;


class OrdersController extends CommonController
{
    /***
     * 确认订单信息
     */
    public function checkOrder(){
        if(!session("User_info")){
            session("beforeLogin","Orders/checkOrder");
            $this->redirect("Login/login");
            exit;
        }
        //根据当前用户id查询购物车商品
        $user_id = session("User_info")["id"];

        $cart_data=D("Carts as a")
            ->join("left join tp_goods as b on a.goods_id =b.id")
            ->field("a.*,b.name as goods_name,b.logo as goods_logo,b.price as goods_price")
            ->where(["user_id"=>$user_id])->select();
        $this->assign("carts",$cart_data);

        //查询出该用户的收货地址分配到页面上
        $addresses = D("Address")->where(["user_id"=>$user_id])->select();
        $this->assign("addresses",$addresses);
        //查询出地区的省  分配给页面
        $provences = D("Locations")->where(["level"=>1])->select();
        $this->assign("provences",$provences);
        //查找快递方式
        $expresses = D("Express")->select();
        $this->assign("expresses",$expresses);
        //查找支付方式
        $payments = D("Payment")->select();
        $this->assign("payments",$payments);
        $this->display();
    }
    /**
     * 生成订单
     */
    public function add(){
        if(!session("User_info")){
            session("beforeLogin","Orders/checkOrder");
            $this->redirect("Login/login");
            exit;
        }
        //数据验证
        $model = D("Orders");
        $data = $model->create();
        if(!$data){
            $this->error("数据出错");
            exit;
        }
//        dump($data);
//        exit;
        //开启事物
        M()->startTrans();
        $lastId = $model->add($data);
        //插入不成功
        if(!$lastId){
            M()->rollback();
            $this->error("数据出错");
            exit;
        }
        //插入成功 ,查找数据库   保存商品信息
        $user_id =session("User_info")["id"];
        $carts =D("Carts as a ")
            ->join("left join tp_goods as b on a.goods_id = b.id")
            ->field("a.*,b.price as goods_price,b.stock")
            ->where(["user_id"=>$user_id])->select();
        $orderItemData =[];
        foreach($carts as $cart){
            //判断库存是否足够,不足则报错
            if($cart["goods_num"]>$cart["stock"]){
                M()->rollback();
                $this->error("库存不足,请减少商品数量");
                exit;
            }
            $item["order_id"] = $lastId;
            $item["goods_id"] = $cart["goods_id"];
            $item["goods_num"] = $cart["goods_num"];
            $item["goods_price"] = $cart["goods_price"];
            $orderItemData[] = $item;
            //需要将购买的数量从商品的库存中减除 并增加销售量
            $res = M("Goods")->where()->where(["id"=>$cart["goods_id"]])
                ->save([
                    "stock"=>["exp","stock-".$cart["goods_num"]],
                    "sale_num"=>["exp","sale_num+".$cart["goods_num"]],

                ]);
            if(!$res){
                M()->rollback();
                $this->error("添加订单失败,请重试");
                exit;
            }
        }
        //批量添加订单的商品信息
        $res = D("OrderItem")->addAll($orderItemData);
        if(!$res){
            M()->rollback();
            $this->error("数据出错");
            exit;
        }
        //删除购物车中数据
        $res = D("Carts")->where(["user_id"=>$user_id])->delete();
        if($res === false ){
            M()->rollback();
            $this->error("数据出错");
            exit;
        }
        //提交数据
        M()->commit();
        $this->success("订单添加成功",U("index"));
    }

    /**
     *显示用户所有订单
     */
    public function index(){
        if(!session("User_info")){
            session("beforeLogin","Orders/index");
            $this->redirect("Login/login");
            exit;
        }
        $user_id = session("User_info")["id"];
        $orders = M("Orders")->where(["user_id"=>$user_id])->select();
        $this->assign("orders",$orders);
        $this->display();
    }


    /**
     * 支付页面
     * @param $order_id  订单id
     */
    public function pay($order_id){
        if(!session("User_info")){
            session("beforeLogin","Orders/checkOrder");
            $this->redirect("Login/login");
            exit;
        }
        $user_id = session("User_info")["id"];
        $order_id = $order_id - 0;
        $order = M("Orders")->where([
            "id"=>$order_id,
            "status"=>1,
            "user_id"=>$user_id
        ])->find($order_id);
        if(!$order){
            $this->error("订单不存在");
            exit;
        }

//        dump($order);
//        exit;
        //支付类型
        $payment_type = "1";
        //必填，不能修改
        //服务器异步通知页面路径
        $notify_url = ADMIN_URL.U("Orders/payReturn");
        //需http://格式的完整路径，不能加?id=123这类自定义参数
        //页面跳转同步通知页面路径
        $return_url = URL.U("Orders/paySuccess");
        //需http://格式的完整路径，不能加?id=123这类自定义参数，不能写成http://localhost/

        //商户订单号
        $out_trade_no = $order["number"];
        //商户网站订单系统中唯一订单号，必填

        //订单名称
        $subject = "源码时代茶叶铺";
        //必填

        //付款金额
        $price = $order["goods_price"];
        //必填

        //商品数量
        $quantity = "1";
        //必填，建议默认为1，不改变值，把一次交易看成是一次下订单而非购买一件商品
        //物流费用
        $logistics_fee = $order["express_price"];
        //必填，即运费
        //物流类型
        $logistics_type = "EXPRESS";
        //必填，三个值可选：EXPRESS（快递）、POST（平邮）、EMS（EMS）
        //物流支付方式
        $logistics_payment = "BUYER_PAY";
        //必填，两个值可选：SELLER_PAY（卖家承担运费）、BUYER_PAY（买家承担运费）
        //订单描述

        $body = "酒类购买";
        //商品展示地址
        $show_url = URL.U("Goods/index");
        //需以http://开头的完整路径，如：http://www.商户网站.com/myorder.html

        //收货人姓名
        $receive_name = $order["name"];
        //如：张三

        //收货人地址
        $receive_address = $order["address"];
        //如：XX省XXX市XXX区XXX路XXX小区XXX栋XXX单元XXX号

        //收货人邮编
        $receive_zip = 611000;
        //如：123456

        //收货人电话号码
        $receive_phone = 0571-88158090;
        //如：0571-88158090

        //收货人手机号码
        $receive_mobile = $order["phone"];
        //如：13312341234

        $alipay_config = C("ALIPAY");
        /************************************************************/

//构造要请求的参数数组，无需改动
        $parameter = array(
            "service" => "create_partner_trade_by_buyer",
            "partner" => trim($alipay_config['partner']),
            "seller_email" => trim($alipay_config['seller_email']),
            "payment_type"	=> $payment_type,
            "notify_url"	=> $notify_url,
            "return_url"	=> $return_url,
            "out_trade_no"	=> $out_trade_no,
            "subject"	=> $subject,
            "price"	=> $price,
            "quantity"	=> $quantity,
            "logistics_fee"	=> $logistics_fee,
            "logistics_type"	=> $logistics_type,
            "logistics_payment"	=> $logistics_payment,
            "body"	=> $body,
            "show_url"	=> $show_url,
            "receive_name"	=> $receive_name,
            "receive_address"	=> $receive_address,
            "receive_zip"	=> $receive_zip,
            "receive_phone"	=> $receive_phone,
            "receive_mobile"	=> $receive_mobile,
            "_input_charset"	=> trim(strtolower($alipay_config['input_charset']))
        );

//建立请求
        vendor("Alipay/AlipaySubmit");
        $alipaySubmit = new \AlipaySubmit($alipay_config);
        $html_text = $alipaySubmit->buildRequestForm($parameter,"get", "确认");
//        dump($html_text);
//        exit;
        echo $html_text;

    }


    public function paySuccess(){
        vendor("Alipay/AlipayNotify");
        $alipay_config = C("ALIPAY");
        $alipayNotify = new \AlipayNotify($alipay_config);
        $verify_result = $alipayNotify->verifyReturn();
        if($verify_result) {//验证成功
            $this->success("付款成功",U("Orders/index"));
            exit;
        } else {
            $this->error("付款失败",U("Orders/index"));
            exit;
        }

    }

    /**
     * 后台接收付款消息
     */
    public function payReturn(){
        //计算得出通知验证结果
        vendor("Alipay/AlipayNotify");
        $alipay_config = C("ALIPAY");
        $alipayNotify = new \AlipayNotify($alipay_config);
        $verify_result = $alipayNotify->verifyNotify();

        if($verify_result) {//验证成功

            //商户订单号
            $out_trade_no = $_POST['out_trade_no'];
            $order =M("Orders")->where(["number"=>$out_trade_no])->find();
            if(!$order){
                echo "fail";
                 exit;
            }
            //支付宝交易号
            $trade_no = $_POST['trade_no'];

            //交易状态
            $trade_status = $_POST['trade_status'];

            $res = M("Orders")->where(["number"=>$out_trade_no])->save([
                "status"=>1,
                "pay_num"=>$trade_no
            ]);
            if($res === false){
                echo "fail";
                exit;
            }
            echo "success";
            if($_POST['trade_status'] == 'WAIT_BUYER_PAY') {
                //该判断表示买家已在支付宝交易管理中产生了交易记录，但没有付款

                //判断该笔订单是否在商户网站中已经做过处理
                //如果没有做过处理，根据订单号（out_trade_no）在商户网站的订单系统中查到该笔订单的详细，并执行商户的业务程序
                //请务必判断请求时的price、quantity、seller_id与通知时获取的price、quantity、seller_id为一致的
                //如果有做过处理，不执行商户的业务程序

                echo "success";		//请不要修改或删除

                //调试用，写文本函数记录程序运行情况是否正常
                //logResult("这里写入想要调试的代码变量值，或其他运行的结果记录");
            }
            else if($_POST['trade_status'] == 'WAIT_SELLER_SEND_GOODS') {
                //该判断表示买家已在支付宝交易管理中产生了交易记录且付款成功，但卖家没有发货

                //判断该笔订单是否在商户网站中已经做过处理
                //如果没有做过处理，根据订单号（out_trade_no）在商户网站的订单系统中查到该笔订单的详细，并执行商户的业务程序
                //请务必判断请求时的price、quantity、seller_id与通知时获取的price、quantity、seller_id为一致的
                //如果有做过处理，不执行商户的业务程序

                echo "success";		//请不要修改或删除

                //调试用，写文本函数记录程序运行情况是否正常
                //logResult("这里写入想要调试的代码变量值，或其他运行的结果记录");
            }
            else if($_POST['trade_status'] == 'WAIT_BUYER_CONFIRM_GOODS') {
                //该判断表示卖家已经发了货，但买家还没有做确认收货的操作

                //判断该笔订单是否在商户网站中已经做过处理
                //如果没有做过处理，根据订单号（out_trade_no）在商户网站的订单系统中查到该笔订单的详细，并执行商户的业务程序
                //请务必判断请求时的price、quantity、seller_id与通知时获取的price、quantity、seller_id为一致的
                //如果有做过处理，不执行商户的业务程序

                echo "success";		//请不要修改或删除

                //调试用，写文本函数记录程序运行情况是否正常
                //logResult("这里写入想要调试的代码变量值，或其他运行的结果记录");
            }
            else if($_POST['trade_status'] == 'TRADE_FINISHED') {
                //该判断表示买家已经确认收货，这笔交易完成

                //判断该笔订单是否在商户网站中已经做过处理
                //如果没有做过处理，根据订单号（out_trade_no）在商户网站的订单系统中查到该笔订单的详细，并执行商户的业务程序
                //请务必判断请求时的price、quantity、seller_id与通知时获取的price、quantity、seller_id为一致的
                //如果有做过处理，不执行商户的业务程序

                echo "success";		//请不要修改或删除

                //调试用，写文本函数记录程序运行情况是否正常
                //logResult("这里写入想要调试的代码变量值，或其他运行的结果记录");
            }
            else {
                //其他状态判断
                echo "success";

                //调试用，写文本函数记录程序运行情况是否正常
                //logResult ("这里写入想要调试的代码变量值，或其他运行的结果记录");
            }

            //——请根据您的业务逻辑来编写程序（以上代码仅作参考）——

            /////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
        }
        else {
            //验证失败
            echo "fail";

            //调试用，写文本函数记录程序运行情况是否正常
            //logResult("这里写入想要调试的代码变量值，或其他运行的结果记录");
        }
    }
}