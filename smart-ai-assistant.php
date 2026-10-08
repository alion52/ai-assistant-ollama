<?php
/**
 * Plugin Name: Smart AI Assistant (Ollama)
 * Description: Локальный ИИ-ассистент для сайта на базе Ollama и модели gemma2:2b. Работает без внешних API.
 * Version: 1.0.0
 * Author: Александр Курносенко
 * License: MIT
 * Text Domain: smart-ai-assistant
 */

if (!defined('ABSPATH')) {
    exit;
}

class Smart_AI_Assistant
{
    private string $ollama_url = 'http://127.0.0.1:11434';
    private string $model = 'gemma2:2b';

    private array $allowed_keywords = [
        // Приветствия и вежливые фразы
        'привет', 'здравствуй', 'здравствуйте', 'добрый', 'доброе',
        'добрый день', 'добрый вечер', 'доброе утро', 'хай', 'hello', 'hi',
        'спасибо', 'благодар', 'пожалуйста',
        'пока', 'до свидания', 'до встречи',
        'как дела', 'как вы', 'как ты',
        'помогите', 'помоги', 'подскажите', 'подскажи',
        'нужна помощь', 'нужна консультация',
        'интересует', 'интересно', 'хочу узнать', 'можно узнать', 'можно вопрос',
        'вы кто', 'кто вы', 'что вы умеете', 'что ты умеешь',
        'чем помочь', 'чем можете помочь', 'чем могу помочь',

        // Услуги и цены
        'услуг', 'цена', 'стоит', 'стоимость', 'прайс', 'бюджет',
        'сайт', 'лендинг', 'магазин', 'интернет-магазин', 'портал',
        'бот', 'telegram', 'телеграм', 'тг',
        'разработ', 'создан', 'сделать', 'заказ',
        'аудит', 'безопасн', 'защит', 'взлом', 'ssl',
        'seo', 'продвижен', 'реклам',
        'приложен', 'pwa', 'мобильн',
        'дизайн', 'верстк', 'вёрстк', 'интерфейс',
        'контакт', 'телефон', 'позвонить', 'email', 'почта', 'написать',
        'срок', 'сколько',
        'как создать', 'как заказать', 'как работает', 'как сделать',
        'что входит', 'расскаж', 'подскаж',
        'заявк', 'оставить заявку', 'менеджер', 'связ',
        'поддержк', 'обслуж', 'доработ', 'доработк',
        'ассистент', 'помощник', 'ai', 'ии',
    ];

    public function __construct()
    {
        add_action('wp_ajax_smart_ai', [$this, 'process']);
        add_action('wp_ajax_nopriv_smart_ai', [$this, 'process']);
    }

    public function process()
    {
        $message = sanitize_text_field($_POST['message'] ?? '');

        if ($message === '__clear__') {
            setcookie('ai_history', '', time() - 3600, '/');
            wp_send_json_success(['message' => 'Очищено']);
            return;
        }

        if ($message === '') {
            wp_send_json_error(['message' => 'Пустое сообщение']);
            return;
        }

        $history = [];
        if (isset($_COOKIE['ai_history'])) {
            $decoded = json_decode(stripslashes($_COOKIE['ai_history']), true);
            if (is_array($decoded)) {
                $history = $decoded;
            }
        }

        $history[] = ['role' => 'user', 'content' => $message];
        if (count($history) > 10) {
            $history = array_slice($history, -10);
        }

        $services = get_posts([
            'post_type' => 'service',
            'posts_per_page' => 50,
            'post_status' => 'publish',
        ]);
        $service_links = [];

        // Фильтр off-topic
        $message_lower = mb_strtolower($message);
        $is_allowed = false;

        foreach ($this->allowed_keywords as $kw) {
            if (mb_strpos($message_lower, $kw) !== false) {
                $is_allowed = true;
                break;
            }
        }

        if (!$is_allowed) {
            wp_send_json_success([
                'answer' => 'Я помогаю только по услугам компании. Спросите про разработку, аудит, цены или контакты.',
                'history' => json_encode($history, JSON_UNESCAPED_UNICODE),
                'service' => null,
            ]);
            return;
        }

        $context = "Ты ассистент компании. Отвечай на русском. Помни диалог.\n";
        $context .= "Наши контакты: телефон +000 (00) 000-00-00, email info@example.com\n\n";

        $context .= "ТВОЯ РОЛЬ:\n";
        $context .= "Ты помогаешь только по темам сайта: услуги, цены, сроки, заявки, контакты.\n";
        $context .= "Если пользователь просто здоровается — ответь приветствием и предложи помощь.\n";
        $context .= "Если спрашивает о чём-то другом — вежливо откажись и предложи помощь по услугам.\n\n";

        $context .= "ПРАВИЛА ОТВЕТА:\n";
        $context .= "- Отвечай МАКСИМУМ 2-3 предложениями. Не больше.\n";
        $context .= "- ВСЕГДА ставь точку в конце. Не обрывай на полуслове.\n";
        $context .= "- Если чувствуешь, что не успеваешь закончить мысль — закончи её коротко и поставь точку.\n";
        $context .= "- Если спрашивают про цену — отвечай одной фразой: 'От X BYN. Точную цену уточним после обсуждения.'\n";
        $context .= "- Если спрашивают контакты — дай именно эти телефон и email. Не выдумывай другие.\n";
        $context .= "- НЕ перечисляй все услуги списком. Если спрашивают 'что ты умеешь' — ответь коротко.\n";
        $context .= "- Не повторяй телефон в каждом ответе.\n\n";

        $context .= "Примеры:\n";
        $context .= "Вопрос: Привет\n";
        $context .= "Ответ: Здравствуйте! Чем могу помочь? Спросите про услуги, цены или контакты.\n\n";
        $context .= "Вопрос: Что ты умеешь?\n";
        $context .= "Ответ: Помогаю с услугами компании: цены, сроки, заявки. Спросите про конкретную услугу.\n\n";
        $context .= "Вопрос: Сколько стоит аудит безопасности?\n";
        $context .= "Ответ: От 450 BYN. Точную цену уточним после обсуждения задачи.\n\n";
        $context .= "Вопрос: Что входит в разработку Telegram-бота?\n";
        $context .= "Ответ: Анализ задач, проектирование сценариев и подключение к вашим сервисам.\n\n";
        $context .= "Вопрос: Какая сегодня погода?\n";
        $context .= "Ответ: Я помогаю только по услугам компании. Спросите про разработку, аудит или цены.\n\n";
        $context .= "Услуги:\n";

        foreach ($services as $s) {
            $price = function_exists('get_field') ? get_field('service_price', $s->ID) : null;
            $url = get_permalink($s->ID);
            $name = $s->post_title;
            $context .= "- {$name}" . ($price ? " (от {$price} BYN)" : "") . "\n";

            $full_lower = mb_strtolower($name);
            $words = preg_split('/[\s,\(\)\-]+/u', $full_lower);
            $keywords = array_values(array_filter($words, function ($w) {
                return mb_strlen($w) > 3;
            }));

            if (mb_strpos($full_lower, 'telegram') !== false || mb_strpos($full_lower, 'телеграм') !== false) {
                $keywords[] = 'тг';
                $keywords[] = 'телеграм';
                $keywords[] = 'telegram';
                $keywords[] = 'бот';
            }

            if (mb_strpos($full_lower, 'сайт') !== false || mb_strpos($full_lower, 'лендинг') !== false) {
                $keywords[] = 'сайт';
                $keywords[] = 'лендинг';
                $keywords[] = 'веб';
            }

            if (mb_strpos($full_lower, 'приложен') !== false || mb_strpos($full_lower, 'pwa') !== false) {
                $keywords[] = 'приложен';
                $keywords[] = 'pwa';
                $keywords[] = 'мобильн';
            }

            $image = get_the_post_thumbnail_url($s->ID, 'medium');
            if (!$image && function_exists('get_field')) {
                $main_image = get_field('main_image', $s->ID);
                if ($main_image) {
                    if (is_array($main_image) && isset($main_image['url'])) {
                        $image = $main_image['url'];
                    } elseif (is_numeric($main_image)) {
                        $image = wp_get_attachment_image_url($main_image, 'medium');
                    }
                }
            }

            $service_links[] = [
                'id' => $s->ID,
                'full' => $name,
                'url' => $url,
                'price' => $price,
                'image' => $image,
                'keywords' => array_unique($keywords),
            ];
        }

        $messages = array_merge([['role' => 'system', 'content' => $context]], $history);

        $response = wp_remote_post($this->ollama_url . '/api/chat', [
            'timeout' => 120,
            'headers' => ['Content-Type: application/json'],
            'body' => json_encode([
                'model' => $this->model,
                'messages' => $messages,
                'stream' => false,
                'options' => ['temperature' => 0.6, 'num_predict' => 90],
            ]),
        ]);

        if (is_wp_error($response)) {
            $this->fallback($message);
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $answer = $body['message']['content'] ?? '';

        if (empty($answer)) {
            $this->fallback($message);
            return;
        }

        $answer = preg_replace('/\*\*(.+?)\*\*/', '$1', $answer);
        $answer = preg_replace('/\*(.+?)\*/', '$1', $answer);
        $answer = trim($answer);

        // Проверка завершённости ответа
        $last_char = mb_substr($answer, -1);
        if (!in_array($last_char, ['.', '!', '?'])) {
            $messages[] = ['role' => 'assistant', 'content' => $answer];
            $messages[] = ['role' => 'user', 'content' => 'Закончи мысль одним коротким предложением и поставь точку.'];

            $response2 = wp_remote_post($this->ollama_url . '/api/chat', [
                'timeout' => 60,
                'headers' => ['Content-Type: application/json'],
                'body' => json_encode([
                    'model' => $this->model,
                    'messages' => $messages,
                    'stream' => false,
                    'options' => ['temperature' => 0.3, 'num_predict' => 50],
                ]),
            ]);

            if (!is_wp_error($response2)) {
                $body2 = json_decode(wp_remote_retrieve_body($response2), true);
                $continuation = trim($body2['message']['content'] ?? '');
                $continuation = preg_replace('/\*\*(.+?)\*\*/', '$1', $continuation);
                $continuation = preg_replace('/\*(.+?)\*/', '$1', $continuation);

                if ($continuation) {
                    $answer = rtrim($answer) . ' ' . ltrim($continuation);
                    $last = mb_substr(trim($answer), -1);
                    if (!in_array($last, ['.', '!', '?'])) {
                        $answer = rtrim($answer) . '.';
                    }
                } else {
                    $answer = rtrim($answer) . '.';
                }
            } else {
                $answer = rtrim($answer) . '.';
            }
        }

        $history[] = ['role' => 'assistant', 'content' => $answer];
        if (count($history) > 10) {
            $history = array_slice($history, -10);
        }
        $history_json = json_encode($history, JSON_UNESCAPED_UNICODE);

        setcookie('ai_history', $history_json, time() + 3600, '/');

        // Поиск услуги
        $answer_lower = mb_strtolower($answer);
        $found_services = [];

        foreach ($service_links as $data) {
            foreach ($data['keywords'] as $kw) {
                if (mb_strpos($answer_lower, $kw) !== false) {
                    $found_services[$data['id']] = $data;
                    break;
                }
            }
        }

        $matched_service = null;
        if (!empty($found_services)) {
            $matched_service = reset($found_services);
        } else {
            foreach ($service_links as $data) {
                foreach ($data['keywords'] as $kw) {
                    if (mb_strpos($message_lower, $kw) !== false) {
                        $matched_service = $data;
                        break 2;
                    }
                }
            }
        }

        $html = nl2br(htmlspecialchars($answer, ENT_QUOTES, 'UTF-8'));

        if (!empty($found_services)) {
            $html .= '<div style="margin-top:14px;padding-top:12px;border-top:1px solid #eee">';
            $html .= '<div style="font-size:13px;color:#666;margin-bottom:8px">Услуги по теме:</div>';

            foreach ($found_services as $data) {
                $html .= '<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">';

                if (!empty($data['image'])) {
                    $html .= '<img src="' . esc_url($data['image']) . '" alt="' . esc_attr($data['full']) . '" '
                        . 'style="width:48px;height:48px;object-fit:cover;border-radius:6px;flex-shrink:0">';
                }

                $html .= '<div style="flex:1;min-width:0">';
                $link = "<a href='{$data['url']}' target='_blank'>{$data['full']}</a>";
                if ($data['price']) {
                    $link .= " — от {$data['price']} BYN";
                }
                $html .= '<div>' . $link . '</div>';
                $html .= '</div></div>';
            }

            $html .= '</div>';
        }

        $service_for_button = null;
        if (count($found_services) === 1) {
            $service_for_button = reset($found_services);
        } elseif (count($found_services) === 0 && $matched_service) {
            $service_for_button = $matched_service;
        }

        wp_send_json_success([
            'answer' => $html,
            'history' => $history_json,
            'service' => $service_for_button ? [
                'id' => $service_for_button['id'],
                'name' => $service_for_button['full'],
                'price' => $service_for_button['price'],
            ] : null,
        ]);
    }

    private function fallback(string $query): void
    {
        $results = [];
        $services = get_posts([
            'post_type' => 'service',
            'posts_per_page' => 5,
            's' => $query,
        ]);

        if (!empty($services)) {
            foreach ($services as $s) {
                $price = function_exists('get_field') ? get_field('service_price', $s->ID) : null;
                $results[] = "<a href='" . get_permalink($s->ID) . "' target='_blank'>{$s->post_title}</a>"
                    . ($price ? " — от {$price} BYN" : "");
            }
        } else {
            $results[] = "Ничего не найдено. Свяжитесь с нами: <a href='tel:+000000000000'>+000 (00) 000-00-00</a>";
        }

        wp_send_json_success(['answer' => implode('<br>', $results)]);
    }
}

new Smart_AI_Assistant();
