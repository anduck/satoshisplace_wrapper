<?php
//17.06.2018 17:33:09 / anduck at anduck dot net
//protect image at satoshis.place
//needs satoshisplace_wrapper.js to query against
//requires php-gd library
$testGD = get_extension_funcs("gd");
if (!$testGD) {
	echo "GD not installed.\n";
	exit();
}

define('INTERVAL', 60); //fetch canvas every 60sec and check if our painting has changed

//coordinate (0,0) is the upper left corner
define('COORDX', 0); //coordinate X
define('COORDY', 0); //coordinate Y

//our image to be drawn on the canvas
define('IMAGE', 'highvoltage_lg.png');



//read image & read the size of image.
$image = imagecreatefromstring(file_get_contents(IMAGE));
$image_width = imagesx($image);
$image_height = imagesy($image);


while (1) {
	//fetch canvas from api
	$canvasdata = json_decode(file_get_contents('http://localhost:8000/?json='.base64_encode('{"command":"GET_LATEST_PIXELS", "payload":""}')),true);
	if ($canvasdata === false) {
		echo "Error fetching canvas data from api.\n";
		sleep(INTERVAL);
	}
	
	//create image from the canvas data which is base64-encoded. strip data:image/bmp;base64, from the data
	$canvas = imagecreatefromstring(base64_decode(mb_substr($canvasdata['data'], mb_strlen('data:image/bmp;base64,'))));

	//read size of the canvas
	$canvas_width = imagesx($canvas);
	$canvas_height = imagesy($canvas);

	echo "Canvas W,H: $canvas_width, $canvas_height px\n";
	echo "Image  W,H: $image_width, $image_height px\n";

	//go through all the pixels and find non-aligning pixels
	$colors = Array();
	for ($x=0; $x<$image_width; $x++) {
		for ($y=0; $y<$image_height; $y++) {
			$pixelcolor_rgb = imagecolorat($image, $x, $y);
			$r = ($pixelcolor_rgb >> 16) & 0xFF;
			$g = ($pixelcolor_rgb >> 8) & 0xFF;
			$b = $pixelcolor_rgb & 0xFF;
			$pixelcolorA = "$r,$g,$b";

			$pixelcolor_rgb = imagecolorat($canvas, COORDX+$x, COORDY+$y);
			$r = ($pixelcolor_rgb >> 16) & 0xFF;
			$g = ($pixelcolor_rgb >> 8) & 0xFF;
			$b = $pixelcolor_rgb & 0xFF;
			$pixelcolorB = "$r,$g,$b";

			$colors[] = Array($pixelcolorA, $pixelcolorB);
			
		}
	}
	$colors_list = "";
	foreach ($colors as $colorpair) 
		$colors_list .= $colorpair[0].", ".$colorpair[1]."\n";
	file_put_contents('colors.txt', $colors_list);
	
	echo "done loop.\n";

	sleep(INTERVAL);
}

$im = imagecreatefrompng("php.png");
$rgb = imagecolorat($im, 10, 15);







?>
