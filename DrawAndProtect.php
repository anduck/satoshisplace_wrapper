<?php
//17.06.2018 17:33:09 / anduck at anduck dot net
//protect image at satoshis.place
//needs satoshisplace_wrapper.js to query against
//requires php-gd library and curl

ini_set('memory_limit', '50M');

$testGD = get_extension_funcs("gd");
if (!$testGD) {
	echo "GD not installed.\n";
	exit();
}

define('APISERVER', 'http://localhost:8000/');
define('INTERVAL', 600); //fetch canvas every X sec and check if our painting has changed

//uncomment and modify to enable automatic LN payments
define('PAY_PAYMENTREQUEST_COMMAND','lncli sendpayment --pay_req=');

//coordinate (0,0) is the upper left corner
define('COORDX', 0); //coordinate X
define('COORDY', 0); //coordinate Y

//our image to be drawn on the canvas
define('IMAGE', 'image.png');
if (file_exists(IMAGE)===false) exit("image not found.\n");

//transparent color
define('TRANSPARENT_COLOR', '#e400ff'); //magenta
list($tr, $tg, $tb) = sscanf(TRANSPARENT_COLOR, "#%02x%02x%02x");


//read image & read the size of image.
$image = imagecreatefromstring(file_get_contents(IMAGE));
$image_width = imagesx($image);
$image_height = imagesy($image);


//fetch satoshis.place canvas settings
echo "fetching settings...\n";
$canvassettings = json_decode(file_get_contents(APISERVER.'?json='.base64_encode('{"command":"GET_SETTINGS", "payload":""}')),true);
echo "got settings. continuing...\n";

if ($canvassettings === false) {
	echo "Error fetching canvas settings (GET_SETTINGS) from api.\n";
	exit();
}
try {
	$pixel_limit = $canvassettings['data']['orderPixelsLimit'];
	$pixel_colors = $canvassettings['data']['colors'];
} catch (Exception $e) {
	exit("Exception: ".$e."\n");
}

//make palette
$palette = imagecreatetruecolor(count($pixel_colors), 1); //width of 1xColors px, height of 1px
$x=0;
foreach ($pixel_colors as $color) {
	list($r, $g, $b) = sscanf($color, "#%02x%02x%02x");
	$pxcolor = imagecolorallocate($palette, $r, $g, $b);
	imagesetpixel($palette, $x, 0, $pxcolor);
	$x++;
}
//imagejpeg($palette, "palette.jpg");

//convert our image to palette colors
for ($x=0; $x<$image_width; $x++) {
	for ($y=0; $y<$image_height; $y++) {
		$pixelcolor_rgb = imagecolorat($image, $x, $y);
		$r = ($pixelcolor_rgb >> 16) & 0xFF;
		$g = ($pixelcolor_rgb >> 8) & 0xFF;
		$b = $pixelcolor_rgb & 0xFF;
		if (($r==$tr) && ($g==$tg) && ($b==$tb)) continue; //transparent px
		
		list($r, $g, $b) = closest_rgb_in_image([$r,$g,$b],$palette);
		$pxcolor = imagecolorallocate($image, $r, $g, $b);
		imagesetpixel($image, $x, $y, $pxcolor);
	}
}
//uncomment to produce output of the palette-coloured image
imagepng($image, "output_image.png");
echo "Starting bot..\n";
sleep(2);


while (1) {
	//fetch canvas from api
	$canvasdata = json_decode(file_get_contents(APISERVER.'?json='.base64_encode('{"command":"GET_LATEST_PIXELS", "payload":""}')),true);
	if ($canvasdata === false) {
		echo "Error fetching canvas data from api.\n";
		sleep(INTERVAL);
	}
	//var_dump($canvasdata); exit();
	
	//create image from the canvas data which is base64-encoded. strip data:image/bmp;base64, from the data
	$canvas = imagecreatefromstring(base64_decode(mb_substr($canvasdata['data'], mb_strlen('data:image/bmp;base64,'))));

	//read size of the canvas
	$canvas_width = imagesx($canvas);
	$canvas_height = imagesy($canvas);

	echo "Canvas W,H: $canvas_width, $canvas_height px\n";
	echo "Image  W,H: $image_width, $image_height px\n";

	//go through all the pixels and find non-aligning pixels
	$pixels_to_order = Array();
	for ($x=0; $x<$image_width; $x++) {
		for ($y=0; $y<$image_height; $y++) {
			$pixelcolorA = imagecolorat($image, $x, $y);
			$pixelcolorB = imagecolorat($canvas, COORDX+$x, COORDY+$y);
			
			if ($pixelcolorA!=$pixelcolorB) {
				$r = ($pixelcolorA >> 16) & 0xFF;
				$g = ($pixelcolorA >> 8) & 0xFF;
				$b = $pixelcolorA & 0xFF;
				if (($r==$tr) && ($g==$tg) && ($b==$tb)) continue; //transparent px
				$pixels_to_order[] = Array('coordinates'=>Array(COORDX+$x, COORDY+$y), 'color'=>sprintf("#%02x%02x%02x", $r, $g, $b));
				if (sprintf("#%02x%02x%02x", $r, $g, $b)=="#000000") exit("found black.\n");
			}
		}
	}

	if (count($pixels_to_order)==0) {
		echo "No pixels to order, means no pixels have changed!\n";
	} else {
		echo "Ordering ".count($pixels_to_order)." pixels...\n";
		//ordering pixels
		$order = Array(
			'command'=>'NEW_ORDER',
			'payload'=>$pixels_to_order
		);
		$json_order = json_encode($order);

		//base64 encode
		$json_order_encoded = base64_encode($json_order);
		file_put_contents('result.txt', 'json='.$json_order_encoded);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, APISERVER);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, 'json='.$json_order_encoded);

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$response = curl_exec ($ch);
		curl_close ($ch);

		echo "RESPONSE: ".$response."\n--------------------\n";
		//automatic payment of paymentRequest
		$response = json_decode($response, true);
		$paymentRequest = $response['data']['paymentRequest'];
		echo "PaymentRequest: ".$paymentRequest."\n";
		if (defined('PAY_PAYMENTREQUEST_COMMAND'))
			exec(PAY_PAYMENTREQUEST_COMMAND.$paymentRequest);
		
	}
	
	echo "done loop. now sleeping for ".INTERVAL." seconds...\n";

	sleep(INTERVAL);
}












////////
//http://php.net/manual/en/function.imagecolorclosest.php  / Hayley Watson code
function warp1($c)
{
    if($c > 10.3148)
    {
        return pow((561 + 40*$c)/10761, 2.4);
    }
    else
    {
        return $c / 3294.6;
    }
}
function warp2($c)
{
    if($c > 0.008856)
    {
        return pow($c, 1/3);
    }
    else
    {
        return 7.787 * $c + 4/29;
    }
}
function rgb2lab($rgb)
{
    [$red, $green, $blue] = array_map('warp1', $rgb);

    $x = warp2($red * 0.4339 + $green * 0.3762 + $blue * 0.1899);
    $y = warp2($red * 0.2126 + $green * 0.7152 + $blue * 0.0722);
    $z = warp2($red * 0.0178 + $green * 0.1098 + $blue * 0.8730);

    $l = 116*$y - 16;
    $a = 500 * ($x - $y);
    $b = 200 * ($y - $z);
    
    return array_map('intval', [$l, $a, $b]);
}

function generate_palette_from_image($image)
{
    $pal = [];
    $width = imagesx($image);
    $height = imagesy($image);
    for($x = 0; $x < $width; ++$x)
    {
        for($y = 0; $y < $height; ++$y)
        {
            $pal[] = imagecolorat($image, $x, $y);
        }
    }
    return array_map(function($col)use($image)
    {
        $rgba = imagecolorsforindex($image, $col);
        return [$rgba['red'], $rgba['green'], $rgba['blue']];
    },    array_unique($pal));
}

function closest_rgb_in_palette($rgb, $palette)
{
    // Quick return when the exact
    // colour is in the palette.
    if(($idx = array_search($rgb, $palette)) !== false)
    {
        return $idx;
    }
    [$tl, $ta, $tb] = rgb2lab($rgb);
    $dists = array_map(function($plab)use($tl, $ta, $tb)
    {
        [$pl, $pa, $pb] = $plab;
        $dl = $pl - $tl;
        $da = $pa - $ta;
        $db = $pa - $tb;
        return $dl * $dl + $da * $da + $db * $db;
    }, array_map('rgb2lab', $palette));
    return array_search(min($dists), $dists);
}

function closest_rgb_in_image($rgb, $image)
{
    $palette = generate_palette_from_image($image);
    return $palette[closest_rgb_in_palette($rgb, $palette)];
}
////////






?>
