<?php
ini_set('date.timezone','Asia/Shanghai');
error_reporting(E_ALL^E_NOTICE^E_WARNING);

require_once(__DIR__."/../config/constants.php");
$database = __DIR__."/../lib/database";

$itemsInfo = []; // Array of itemInfo
if (file_exists($database)) {
    $itemsInfoOld = unserialize(file_get_contents($database)); // Array of last itemInfo
}


function emailPriceDrop($itemName, $itemPrice, $itemPriceOld, $country)
{
    $to = "username@domain.com";
    $shorterName = preg_split('/(,|\.|:|;|，|。|：|；)/', $itemName)[0];
    
    if ($country == "cn") {
        $subject = $shorterName." 降价至".$itemPrice."元";
        $subject = "=?UTF-8?B?" . base64_encode($subject) . "?=";
        $message = $shorterName." 原价".$itemPriceOld."元，现在".$itemPrice."元\n";
    }
    if ($country == "com") {
        $subject = $shorterName." DROPS TO $".$itemPrice;
        //$subject = "=?UTF-8?B?" . base64_encode($subject) . "?=";
        $message = $shorterName."\nOld price was $".$itemPriceOld.", now is $".$itemPrice."\n";
    }
    
    /*
    print_r(array(
        'to' => $to,
        'subject' => $subject,
        'message' => $message
        ));
    */
    mail($to, $subject, $message);
}

function findProductInfo($itemId)
{
    global $itemsInfo;
    global $itemsInfoOld;
    global $country;
    $amazon_url = 'http://www.amazon.'.$country.'/dp/'.$itemId;
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        //CURLOPT_VERBOSE => true,
        CURLOPT_URL => $amazon_url,
        CURLOPT_USERAGENT => "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_9_4) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/50.0.2661.86 Safari/537.36",
    ));


    while (true) { //Repeat the request until applicable response
        
        // Run the curl
        $responseHTML = curl_exec($curl);
        // If cURL cannot reach site, must be wrong id, skip it
        if (!$responseHTML) break;
        if (empty($responseHTML)) {
            sleep(60);
            continue;
        }

        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML($responseHTML);
        
        // itemPrice could be in a few different spans
        if (!isset($itemPrice) || empty($itemPrice)) { // Kindle Ebook
            $finder = new DomXPath($doc);
            $classname="a-color-price";
            $nodes = $finder->query("//span[contains(@class, '$classname')]");
            //$itemPrice = trim($nodes->item(0)->textContent,"￥$"); // Only suits amazon.cn
            $temp = $nodes->item(0)->textContent;
        }
        $itemPrice = preg_replace('@[^0-9.]@', '', $temp);

        // itemName
        $itemName = $doc->getElementById('ebooksProductTitle')->textContent;
        if (!isset($itemName) || empty($itemName)) {
            $itemName = trim($doc->getElementById('productTitle')->textContent);
        }
        
        // itemStar
        $temp = $doc->getElementById('reviewStarsLinkedCustomerReviews')->textContent;
        preg_match_all('@[0-9]+(?:\.[0-9]+)?@', $temp, $tempReg);
        $itemStar = $tempReg[0][0];
        
        // itemCommentCount
        $itemCommentCount = explode(" ",$doc->getElementById('acrCustomerReviewText')->textContent)[0];
        

        // Save ItemInfo to Database
        if (!empty($itemName)) {
            $itemsInfo[$itemId] = array(
                "currentTime" => date("Y-m-d H:i:s", time()),
                "itemName" => $itemName,
                "itemPrice" => $itemPrice,
                "itemStar" => $itemStar,
                "itemCommentCount" => $itemCommentCount
            );
            // Compare ItemPrice
            if (isset($itemsInfoOld[$itemId])) {
                $itemPriceOld = $itemsInfoOld[$itemId]['itemPrice'];
                if (intval($itemPrice) < intval($itemPriceOld)) {
                    emailPriceDrop($itemName, $itemPrice, $itemPriceOld, $country);
                }
            }
            //print_r($itemsInfo[$itemId]);
            break;
        }// else sleep(300);
    } // end while

    // Close the curl, free resources
    curl_close($curl);
}


// Get ItemInfo one by one
foreach ($itemsId as $itemId) {
    findProductInfo($itemId);
}

// Write the item id, item name, item price, etc. to the database file
$itemsInfoSerized = serialize($itemsInfo);
file_put_contents($database, $itemsInfoSerized);

?>