<?php
declare(strict_types=1);$id=(int)($_GET['id']??0);header('Location: product_quotes.php'.($id>0?'?id='.$id:''),true,302);exit;