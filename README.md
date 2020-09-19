# qlmarkdown - Joomla! content plugin

The extension _plg_content_qlmarkdown_  allows you to parse markdown data into valid html code; various numbers of parsers are available. 

~~~html
{'qlmarkdown}your markdown text to be parsed{/qlmarkdown}
~~~ 

You can add html id and slass and style attributes like this: 

~~~html
{'qlmarkdown class="important" style="background:red;" id="someIdThatMeansSomethingToYou"}your markdown text to be parsed{/qlmarkdown}
~~~

Following parameters can be added:  

* class: css classe
* id: css id
* style: css style commands
* title: NOT used in default template, but mae be you might need it in override
* layout: layout to be used, `default.php` ist used by default
    * can be globally overridden in plugin param
    * can be locally overridden in plugin call tag {'qlmarkdown class="some-other-layout"} ...<br />it will use the file `some-other-layout.php` in template folder `template/YOURTEMPLATE/html/plg_content_qlmarkdown/some-other-layout.php`  
