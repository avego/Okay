<?php

require_once('View.php');

class ProductView extends View {
    
    public function fetch() {
        $product_url = $this->request->get('product_url', 'string');
        
        if(empty($product_url)) {
            return false;
        }
        
        // Выбираем товар из базы
        $product = $this->products->get_product((string)$product_url);
        if(empty($product) || (!$product->visible && empty($_SESSION['admin']))) {
            return false;
        }
        
        //lastModify
        $this->setHeaderLastModify($product->last_modify);
        
        $product->images = $this->products->get_images(array('product_id'=>$product->id));
        $product->image = reset($product->images);
        
        $variants = array();
        foreach($this->variants->get_variants(array('product_id'=>$product->id)) as $v) {
            $variants[$v->id] = $v;
        }
        
        $product->variants = $variants;
        
        // Вариант по умолчанию
        if(($v_id = $this->request->get('variant', 'integer'))>0 && isset($variants[$v_id])) {
            $product->variant = $variants[$v_id];
        } else {
            $product->variant = reset($variants);
        }
        
        $product->features = $this->features->get_product_options(array('product_id'=>$product->id));
        
        // Автозаполнение имени для формы комментария
        if(!empty($this->user)) {
            $this->design->assign('comment_name', $this->user->name);
        }
        
        // Принимаем комментарий
        if ($this->request->method('post') && $this->request->post('comment')) {
            $comment = new stdClass;
            $comment->name = $this->request->post('name');
            $comment->text = $this->request->post('text');
            $captcha_code =  $this->request->post('captcha_code', 'string');
            
            // Передадим комментарий обратно в шаблон - при ошибке нужно будет заполнить форму
            $this->design->assign('comment_text', $comment->text);
            $this->design->assign('comment_name', $comment->name);
            
            // Проверяем капчу и заполнение формы
            if ($this->settings->captcha_product && ($_SESSION['captcha_code'] != $captcha_code || empty($captcha_code))) {
                $this->design->assign('error', 'captcha');
            } elseif (empty($comment->name)) {
                $this->design->assign('error', 'empty_name');
            } elseif (empty($comment->text)) {
                $this->design->assign('error', 'empty_comment');
            } else {
                // Создаем комментарий
                $comment->object_id = $product->id;
                $comment->type      = 'product';
                $comment->ip        = $_SERVER['REMOTE_ADDR'];
                
                // Если были одобренные комментарии от текущего ip, одобряем сразу
                $this->db->query("SELECT 1 FROM __comments WHERE approved=1 AND ip=? LIMIT 1", $comment->ip);
                if($this->db->num_rows()>0) {
                    $comment->approved = 1;
                }
                
                // Добавляем комментарий в базу
                $comment_id = $this->comments->add_comment($comment);
                
                // Отправляем email
                $this->notify->email_comment_admin($comment_id);
                
                // Приберем сохраненную капчу, иначе можно отключить загрузку рисунков и постить старую
                unset($_SESSION['captcha_code']);
                header('location: '.$_SERVER['REQUEST_URI'].'#comment_'.$comment_id);
            }
        }
        
        // Связанные товары
        $related_ids = array();
        $related_products = array();
        foreach($this->products->get_related_products($product->id) as $p) {
            $related_ids[] = $p->related_id;
            $related_products[$p->related_id] = null;
        }
        if(!empty($related_ids)) {
            foreach($this->products->get_products(array('id'=>$related_ids, 'visible'=>1, 'in_stock'=>1)) as $p) {
                $related_products[$p->id] = $p;
            }
            
            $related_products_images = $this->products->get_images(array('product_id'=>array_keys($related_products)));
            foreach($related_products_images as $related_product_image) {
                if(isset($related_products[$related_product_image->product_id])) {
                    $related_products[$related_product_image->product_id]->images[] = $related_product_image;
                }
            }
            $related_products_variants = $this->variants->get_variants(array('product_id'=>array_keys($related_products)));
            foreach($related_products_variants as $related_product_variant) {
                if(isset($related_products[$related_product_variant->product_id])) {
                    $related_products[$related_product_variant->product_id]->variants[] = $related_product_variant;
                }
            }
            foreach($related_products as $id=>$r) {
                if(is_object($r)) {
                    $r->image = $r->images[0];
                    $r->variant = $r->variants[0];
                } else {
                    unset($related_products[$id]);
                }
            }
            $this->design->assign('related_products', $related_products);
        }
        
        // Отзывы о товаре
        $comments = $this->comments->get_comments(array('type'=>'product', 'object_id'=>$product->id, 'approved'=>1, 'ip'=>$_SERVER['REMOTE_ADDR']));
        
        // И передаем его в шаблон
        $this->design->assign('product', $product);
        $this->design->assign('comments', $comments);
        
        // Категория и бренд товара
        $product->categories = $this->categories->get_categories(array('product_id'=>$product->id));
        $this->design->assign('brand', $this->brands->get_brand(intval($product->brand_id)));
        $category = reset($product->categories);
        $this->design->assign('category', $category);

        // Соседние товары
        if (!empty($category)) {
            $neighbors_products = $this->products->get_neighbors_products($category->id, $product->position);
            $this->design->assign('next_product', $neighbors_products['next']);
            $this->design->assign('prev_product', $neighbors_products['prev']);
        }
        
        // Добавление в историю просмотров товаров
        $max_visited_products = 100; // Максимальное число хранимых товаров в истории
        $expire = time()+60*60*24*30; // Время жизни - 30 дней
        if(!empty($_COOKIE['browsed_products'])) {
            $browsed_products = explode(',', $_COOKIE['browsed_products']);
            // Удалим текущий товар, если он был
            if(($exists = array_search($product->id, $browsed_products)) !== false) {
                unset($browsed_products[$exists]);
            }
        }
        // Добавим текущий товар
        $browsed_products[] = $product->id;
        $cookie_val = implode(',', array_slice($browsed_products, -$max_visited_products, $max_visited_products));
        setcookie("browsed_products", $cookie_val, $expire, "/");
        
        //Автоматичекска генерация мета тегов и описания товара
        if (!empty($category)) {
            $parts = array(
                '{$category}' => ($category->name ? $category->name : ''),
                '{$category_h1}' => ($category->name_h1 ? $category->name_h1 : ''),
                '{$brand}' => ($this->design->get_var('brand') ? $this->design->get_var('brand')->name : ''),
                '{$product}' => ($product->name ? $product->name : ''),
                '{$price}' => ($product->variant->price != null ? $this->money->convert($product->variant->price, $this->currency->id, false).' '.$this->currency->sign : ''),
                '{$sitename}' => ($this->settings->site_name ? $this->settings->site_name : '')
            );
            foreach ($product->features as $feature) {
                if ($feature->auto_name_id) {
                    $parts['{$'.$feature->auto_name_id.'}'] = $feature->name;
                }
                if ($feature->auto_value_id) {
                    $parts['{$'.$feature->auto_value_id.'}'] = $feature->value;
                }
            }
            
            $auto_meta_title = ($category->auto_meta_title ? $category->auto_meta_title : $product->meta_title);
            $auto_meta_keywords = ($category->auto_meta_keywords ? $category->auto_meta_keywords : $product->meta_keywords);
            $auto_meta_description = ($category->auto_meta_desc ? $category->auto_meta_desc : $product->meta_description);

            $auto_meta_title = strtr($auto_meta_title, $parts);
            $auto_meta_keywords = strtr($auto_meta_keywords, $parts);
            $auto_meta_description = strtr($auto_meta_description, $parts);
            if (!empty($category->auto_body) && empty($product->body)) {
                $product->body = strtr($category->auto_body, $parts);
                $product->body = preg_replace('/\{\$[^\$]*\}/', '', $product->body);
            }
            $auto_meta_title = preg_replace('/\{\$[^\$]*\}/', '', $auto_meta_title);
            $auto_meta_keywords = preg_replace('/\{\$[^\$]*\}/', '', $auto_meta_keywords);
            $auto_meta_description = preg_replace('/\{\$[^\$]*\}/', '', $auto_meta_description);

            $this->design->assign('meta_title', $auto_meta_title);
            $this->design->assign('meta_keywords', $auto_meta_keywords);
            $this->design->assign('meta_description', $auto_meta_description);
        } else {
            $this->design->assign('meta_title', $product->meta_title);
            $this->design->assign('meta_keywords', $product->meta_keywords);
            $this->design->assign('meta_description', $product->meta_description);
        }
        
        return $this->design->fetch('product.tpl');
    }
    
}
