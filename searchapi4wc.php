<?php
/*
Plugin Name: SearchAPI for Woocommerce
Plugin URI: https://searchapi.pl
Description: Plugin wspomagający wyszukiwanie produktów w sklepach opartych o Woocommerce.
Author: Andrzej Bernat
Version: 1.0.0
Author URI: https://searchapi.pl
 */

add_action("admin_menu", "searchapi_plugin_setup_menu");

if (get_option("searchapi_website_uuid") && get_option("searchapi_website_secret_key")) {
    add_action('woocommerce_before_main_content', 'search_input', 10);
}

/**
 *
 * @return void
 */
function search_input()
{
    if (get_option("searchapi_website_uuid")) {
        echo '
        <div id="searchapi-wrapper">
        <input type="text" id="searchapi-input" autocomplete="off" class="form-control" placeholder="Search our plugins..." />
        <div id="searchapi-results" style="display: none;"></div>
          <script>
            var searchapiApiKey = "' . get_option("searchapi_website_uuid") . '";
            var d = document,
              b = d.getElementsByTagName("body")[0],
              searchApiConfig = {
                "containers": {
                  inputId: "searchapi-input",
                  resultsId: "searchapi-results",
                },
                "dictonary": {
                  "currency": "$",
                  "price_starts_from": "From",
                  "recent_queries": "Recently searched by me",
                  "most_popular_queries": "Popular searches",
                  "most_popular_products": "Popular products",
                  "filter_by_category": "Filter by category",
                  "filter_by_category_reset": "All",
                  "filter_by_price": "Filter by price",
                  "filter_by_price_from": "from",
                  "filter_by_price_to": "to",
                  "no_results": "Nothing found for the given conditions",
                  "products_found": "Matching products"                  
                }
              },
              l = d.createElement("link");
              s = d.createElement("script");
              s.type = "text/javascript";
              s.async = true;
              s.src = "https://quicksearchapi.com/searchapi.js";
              b.appendChild(s);
              l.setAttribute("rel", "stylesheet");
              l.setAttribute("href", "https://quicksearchapi.com/searchapi.min.css");
              b.appendChild(l);
          </script>
        </div>
        ';
    }
}

/**
 * Fetches and indexes given products into Searchapi.pl engine
 *
 * @return array
 */
function searchapi_fetch_products_and_index()
{

    $products = wc_get_products(array(
        'post_type' => 'product',
        'post_status' => 'publish',
        'numberposts' => -1,
    ));

    $index_log = [];
    $products_indexed_counter = 1;
    foreach ($products as $product) {
        $product = [
            "title" => $product->get_name(),
            "description" => $product->get_description(),
            "path" => str_replace(home_url(), '', $product->get_permalink()),
            "image" => wp_get_attachment_url($product->get_image_id()),
            "tags" => implode(",", wp_get_post_terms($product->get_id(), 'product_tag', array('fields' => 'names'))),
            "price" => $product->get_price(),
	    "category" => trim(explode(',', $product->get_categories( ',', ' ' . _n( ' ', '  ', $cat_count, 'woocommerce' ) . ' ', ' ' ))[0])
        ];

        $response = api_add_page($product);

        if ($response["code"] == "ok") {
            $index_log[] = "[OK] Produkt <b>{$product['title']}</b> o adresie {$product['path']} został zaindeksowany.";
            $products_indexed_counter++;
        } else {
            $index_log[] = "[ERR] Podczas indeksowania produkt {$product['title']} wystąpiły błędy.";
        }
    }

    return [
        'index_log' => $index_log,
        'products_indexed_counter' => $products_indexed_counter - 1,
    ];
}

/**
 * Displays do-index confirmation screen
 * @type Action
 *
 * @return void
 */
function searchapi_confirm_index_products_action()
{
    $index_updated_at = get_option("searchapi_index_products_action_updated_at");

    if ($index_updated_at) {
        $msg = "Ostatnia aktualizacja indeksu produktów miała miejsce {$index_updated_at}.";
    } else {
        $msg = "Indeks wyszukiwarki jeszcze nigdy nie został uzupełniony";
    }

    echo '
    <div class="wrap">
        <h1 class="wp-heading-inline">Wyszukiwarka produktów Searchapi.pl</h1>
        <hr class="wp-header-end">
        <div class="notice notice-info">
            <p><strong>Indeksuj produkty z katalogu</strong></p>
            <p>Aby Twoje produkty pojawiły się w wynikach wyszukiwania, musisz je zindeksować. Jeśli masz dużo produktów, może to trochę potrwać. Po zindeksowaniu produkty powinny pojawić się w ciągu kilku godzin, ale zazwyczaj jest to szybsze.' . $msg . '.</p>
            <p class="submit" style="margin-top:0px; padding-top:0px;">
                <form action="/wp-admin/admin.php?page=searchapi&action=do_index" method="POST">
                    <button type="submit" class="button-primary">Aktualizuj produkty</button>&nbsp;&nbsp;&nbsp;&nbsp;
                    <a href="/wp-admin/admin.php?page=searchapi&action=flush_index" class="button-secondary">Zregeneruj indeks</a>
                    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                    <a href="/wp-admin/admin.php?page=searchapi&action=create_account" class="button-secondary">Klucze API</a>
                </form>
            </p>
            <p>Jeśli dodałeś nowe produkty lub zaktualizowałeś już istniejące, użyj opcji "Aktualizuj produkty". Jeśli natomiast usunąłeś jakieś produkty z oferty, skorzystaj z opcji "Zregeneruj indeks". Różnica między tymi dwoma opcjami polega na tym, że pierwsza pozwala na aktualizację indeksu bez jego usuwania, dzięki czemu produkty są nadal dostępne dla wyszukiwarki. Natomiast druga opcja polega na całkowitym usunięciu i ponownym utworzeniu indeksu, w wyniku czego produkty będą dostępne dopiero po przeindeksowaniu systemowym przez Searchapi.pl, które odbywa się co kilka godzin.</p>
        </div>
    </div>
    ';
}

/**
 * Flushes the index and re-indexes the products
 * @type Action
 *
 * @return void
 */
function searchapi_flush_products_action()
{
    $response = api_website_flush();
    if ($response["code"] == "ok") {
        header("Location: /wp-admin/admin.php?page=searchapi&action=do_index");
    } else {
        $label = "Wystapiły błędy";
        $noticeBox = "<div class='notice notice-error'>
            <p>{$response["message"]}</p>
        </div>";
        echo "
        <div class='wrap'>
            <h1 class='wp-heading-inline'>{$label}</h1>
            <hr class='wp-header-end'>
            {$noticeBox}
			<p class='submit'>
				<a href='/wp-admin/admin.php?page=searchapi' class='button-primary'>Powrót</a>
			</p>
        </div>
    ";
    }
}

/**
 * Displays after-index page
 * @type Action
 *
 * @return void
 */
function searchapi_index_products_action()
{
    update_option("searchapi_index_products_action_updated_at", date('Y-m-d H:i'));
    $result = searchapi_fetch_products_and_index();

    $index_log = implode("<br/>", $result['index_log']);
    $counter = $result['products_indexed_counter'];

    echo "
    <div class='wrap'>
        <h1 class='wp-heading-inline'>Produkty ({$counter}) zostały zaindeksowane</h1>
        <hr class='wp-header-end'>
        <div class='notice notice-info'>
            <p>Twoje produkty pojawią się w wyszukiwarce za kilka godzin. Jeśli chcesz, aby proces ten przebiegł szybciej, możesz napisać do nas na adres andrzej@itma.pl lub zadzwonić pod numer 530 861 858. Chętnie Ci pomożemy.</p>
            <div style='width:100%; height:200px; overflow-y: scroll; margin-top:30px;'>
                {$index_log}
            </div>
            <p class='submit'>
                <a href='/wp-admin/admin.php?page=searchapi' class='button-primary'>Powrót</a>
            </p>
        </div>
    </div>
    ";
}

/**
 * Shows new account form to join Searchapi.pl
 * @type Action
 *
 * @return void
 */
function searchapi_new_account_action()
{
    echo '
        <div class="wrap">
            <h1 class="wp-heading-inline">Wyszukiwarka produktów Searchapi.pl</h1>
            <hr class="wp-header-end">
            <div class="notice notice-info">
                <p><strong>Dokończ instalację</strong></p>
                <p>Aby umożliwić swoim klientom korzystanie z auto podpowiedzi w wyszukiwarce należy zaindeksować ofertę sklepu. Indeksacja będzie możliwa po dokończeniu instalacji. Po kliknięciu w przycisk "Dokończ instalację" zostanie utworzone konto sklepu w usłudze searchapi.pl, w którym będzie przetwarzana oferta sklepu tak aby Twoi klienci mogli z łatwością odnaleźć produkty w wyszukiwarce. Konto jest darmowe.</p>
                <p class="submit">
                    <form action="/wp-admin/admin.php?page=searchapi&action=create_account" method="POST">
                    <input type="text" name="searchapi_account_email" placeholder="Adres e-mail administratora" />
                        ' . (isset($_GET["err"]) && $_GET["err"] == 1 ? '<div style="padding: 4px 0 0px 8px; color: #ff0000;">Podany email nie jest poprawny</div><br/>' : "") . '
                        <button type="submit" class="button-primary">Zapisz i dokończ instalację</button>
                        </form>
                    </p>
            </div>
        </div>
    ';
}

/**
 * Perform the api call and deletes the account, after that shows the confirmation screen.
 * @type Action
 *
 * @return void
 */
function searchapi_delete_account_action()
{
    $response = api_delete_account();
    if ($response["code"] == "ok") {

        update_option("searchapi_account_uuid", false);
        update_option("searchapi_account_secret_key", false);
        update_option("searchapi_website_uuid", false);
        update_option("searchapi_website_secret_key", false);

        $label = "Konto zostało usunięte";
        $noticeBox = "<div class='notice notice-success'>
            <p>Konto wraz ze wszystkimi danymi zostało usunięte z usługi Searchapi.pl. Możesz ponownie założyć konto na dowolny adres e-mail i ponownie użyć nowego konta w tym sklepie.</p>
        </div>";

    } else {
        $label = "Wystapiły błędy";
        $noticeBox = "<div class='notice notice-error'>
            <p>{$response["message"]}</p>
        </div>";
    }

    echo "
        <div class='wrap'>
            <h1 class='wp-heading-inline'>{$label}</h1>
            <hr class='wp-header-end'>
            {$noticeBox}
			<p class='submit'>
				<a href='/wp-admin/admin.php?page=searchapi' class='button-primary'>Powrót</a>
			</p>
        </div>
    ";
}

/**
 * Perform the api call and creates new account, after that shows the confirmation screen.
 * @type Action
 *
 * @return void
 */
function searchapi_create_account_action()
{
    if (get_option("searchapi_account_uuid") == false) {
        if (filter_var($_POST["searchapi_account_email"], FILTER_VALIDATE_EMAIL)) {
            $response = api_create_account($_POST);
            if ($response["code"] == "ok") {
                $label = "Nowe konto zostało utworzone";

                update_option("searchapi_account_uuid", $response["data"]["X-Auth-Key"]);
                update_option("searchapi_account_secret_key", $response["data"]["X-Auth-Secret"]);

                $response = api_create_website($response);
                if ($response["code"] == "ok") {

                    update_option("searchapi_website_uuid", $response["data"]["X-Auth-Key"]);
                    update_option("searchapi_website_secret_key", $response["data"]["X-Auth-Secret"]);

                    $label = "Nowa strona została utworzona";
                    $noticeBox = "<div class='notice notice-success'>
                        <p>Nowe konto zostało pomyślnie utworzone. Poniższe dane będą Ci potrzebne jedynie w przypadku kiedy będziesz chciał dokonywać zaawansowanych zmian w komunikacji plugina z usługą Searchapi.pl. Jeśli nie zamierzasz takich zmian dokonywac nie musisz zapisywać tych danych.</p>
                    </div>";

                } else {
                    $label = "Wystapiły błędy";
                    $noticeBox = "<div class='notice notice-error'>
                        <p>{$response["message"]}</p>
                    </div>";
                }
            } else {
                $label = "Wystapiły błędy";
                $noticeBox = "<div class='notice notice-error'>
                    <p>{$response["message"]}</p>
                </div>";
            }
        } else {
            header("Location: /wp-admin/admin.php?page=searchapi&err=1");
        }
    } else {
        $label = "Dane konta";
    }

    $account_uuid = get_option("searchapi_account_uuid");
    $account_secret = get_option("searchapi_account_secret_key");
    $website_uuid = get_option("searchapi_website_uuid");
    $website_secret = get_option("searchapi_website_secret_key");

    echo "
        <div class='wrap'>
            <h1 class='wp-heading-inline'>{$label}</h1>
            <hr class='wp-header-end'>
            {$noticeBox}

			<div class='notice notice-info'>
			    <h3>Klucze API konta w Searchapi.pl</h3>
                <p>Jeśli chcesz samodzielnie dodać nowy sklep pod jednym kontem, możesz użyć kluczy API sklepu. To bardziej zaawansowana metoda i jest skierowana do bardziej doświadczonych użytkowników. Więcej informacji znajdziesz <a href='https://searchapi.pl' target='_blank'>w dokumentacji</a>.</p>
			    <p>
                    <strong>Identyfikator konta:</strong>
                    {$account_uuid}
                </p>
			    <p>
                    <strong>Sekretny klucz:</strong>
                    {$account_secret}
                </p>
			</div>

			<div class='notice notice-info'>
			    <h3>Klucze API sklepu</h3>
                <p>Jeśli chcesz samodzielnie aktualizować ofertę swojego sklepu, możesz użyć kluczy API. To bardziej zaawansowana metoda i jest skierowana do bardziej doświadczonych użytkowników. Więcej informacji znajdziesz <a href='https://searchapi.pl' target='_blank'>w dokumentacji</a>.</p>
			    <p>
                    <strong>Identyfikator konta:</strong>
                    {$website_uuid}
                </p>
			    <p>
                    <strong>Sekretny klucz:</strong>
                    {$website_secret}
                </p>
			</div>
			<p class='submit'>
				<a href='/wp-admin/admin.php?page=searchapi' class='button-primary'>Powrót</a>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                <a href='/wp-admin/admin.php?page=searchapi&action=delete_account' class='button-secondary' onclick='return confirm(\"Usuwając swoje konto w Searchapi.pl, usuniesz wszystkie swoje produkty z indeksu, co sprawi, że nie będzie możliwe ich wyszukiwanie. Ta operacja jest ostateczna i nieodwracalna. Czy na pewno chcesz kontynuować?\");'>Usuń konto</a>
			</p>
        </div>
    ";
}

/**
 * Calls Searchapi. API endpoint
 * @type API
 *
 * @return void
 */
function api_delete_account()
{
    $ch = curl_init("https://searchapi.pl/api/account/delete");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "X-Auth-Key: " . get_option("searchapi_account_uuid"),
        "X-Auth-Secret: " . get_option("searchapi_account_secret_key"),
    ]);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $json = curl_exec($ch);
    curl_close($ch);
    return json_decode($json, true);
}

/**
 * Calls Searchapi. API endpoint
 * @type API
 *
 * @return void
 */
function api_create_account($input)
{
    $ch = curl_init("https://searchapi.pl/api/account/create");
    $payload = json_encode([
        "email" => $input["searchapi_account_email"],
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type:application/json",
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $json = curl_exec($ch);
    curl_close($ch);
    return json_decode($json, true);
}

/**
 * Calls Searchapi. API endpoint
 * @type API
 *
 * @return void
 */
function api_create_website($input)
{
    $ch = curl_init("https://searchapi.pl/api/website/create");
    $payload = json_encode([
        "host" => $_SERVER['SERVER_NAME'],
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "X-Auth-Key: " . $input["data"]["X-Auth-Key"],
        "X-Auth-Secret: " . $input["data"]["X-Auth-Secret"],
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $json = curl_exec($ch);
    curl_close($ch);
    return json_decode($json, true);
}

/**
 * Calls Searchapi. API endpoint
 * @type API
 *
 * @return void
 */
function api_add_page(array $data)
{
    $ch = curl_init("https://searchapi.pl/api/page/add");

    $payload = json_encode($data);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "X-Auth-Key: " . get_option("searchapi_website_uuid"),
        "X-Auth-Secret: " . get_option("searchapi_website_secret_key"),
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $json = curl_exec($ch);
    curl_close($ch);
    return json_decode($json, true);
}

/**
 * Calls Searchapi. API endpoint
 * @type API
 *
 * @return void
 */
function api_website_flush()
{
    $ch = curl_init("https://searchapi.pl/api/website/flush");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "X-Auth-Key: " . get_option("searchapi_website_uuid"),
        "X-Auth-Secret: " . get_option("searchapi_website_secret_key"),
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    $json = curl_exec($ch);
    curl_close($ch);
    return json_decode($json, true);
}

/**
 * Action router
 */

switch ($_GET["action"]) {
    case 'create_account':
        $function = "searchapi_create_account_action";
        break;
    case 'delete_account':
        $function = "searchapi_delete_account_action";
        break;
    case 'do_index':
        $function = "searchapi_index_products_action";
        break;
    case 'flush_index':
        $function = "searchapi_flush_products_action";
        break;
    default:
        $function = null;
}

if (is_null($function)) {
    if (get_option("searchapi_account_uuid") == false) {
        $function = "searchapi_new_account_action";
    } else {
        $function = "searchapi_confirm_index_products_action";
    }
}

/**
 * Admin menu hook
 *
 * @return void
 */
function searchapi_plugin_setup_menu()
{
    global $function;
    add_menu_page(
        "Wyszukiwarka produktów",
        "Searchapi.pl",
        "manage_options",
        "searchapi",
        $function
    );
}
