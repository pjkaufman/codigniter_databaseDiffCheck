<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<head>
    <meta charset="utf-8">
    <!----JQuery CDNs ---->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
    <!----Bootstrap CDNs ---->
    <link rel="stylesheet" type="text/css" rel="stylesheet" type="text/css" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.3.7/css/bootstrap.min.css"/>
    <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.2.0/css/font-awesome.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.3.7/js/bootstrap.min.js"></script>
</head>
<body>
  <div class="content">
    <h2 style="text-align:center">The Database Difference Checker</h2>
    <div>
      <p>The Database Difference Checker can take a snapshot of a database and compare two databases based on user input</p>
    </div>
    <div class="options">
      <div class="Snapshot" style="display: inline-block">
      <button class="glyphicon glyphicon-camera .btn-default"> Snapshot </button>
    </div>
    <div class="DB2" style="display: inline-block">
    <button class="glyphicon glyphicon-duplicate .btn-default"> Database Comparison (2 DB's)</button>
  </div>
  <div class="DB1" style="display: inline-block">
  <button class="glyphicon glyphicon-file .btn-default"> Database Comparison (1 DB)</button>
</div>
<div class="reset" style="display: inline-block">
<button class="glyphicon glyphicon-refresh .btn-default"> Reset </button>
</div>
<div class="spinner" style="display: inline-block>
<i class="fa fa-spinner fa-spin" style="font-size:24px"></i>
</div>
  <div class="result">
    <h4 id="rTitle"></h4>
    <pre style='padding: 20px; background-color: #FFFAF0'></pre>
  </div>
    </div>
  </div>
  <script>
  "use strict";
  $(document).ready(function() {
  	$('pre').hide();
  	$('.spinner').hide();
  	$('div.reset').click(function() {
  		$('h4#rTitle').text('');
  		$('pre').hide();
  		$('pre').empty();
  	});
  	$('div.Snapshot').click(function() {
  		$('.spinner').show();
  		$.ajax({
  			type: "POST",
  			url: location + '/take_snapshot',
  			success: function() {
  				$('.spinner').hide();
  				$('h4#rTitle').text("Database Snapshot was taken.");
  			},
  		});
  	});
  	$('div.DB2').click(function() {
  		$('.spinner').show();
  		var data = {
  			num: 'false'
  		};
  		$.ajax({
  			type: "POST",
  			data: data,
  			url: location + '/db_compare',
  			success: function(resp) {
  				$('.spinner').hide();
  				var todo = $.parseJSON(resp);
  				$('h4#rTitle').text(todo.title);
  				$('pre').empty();
  				$.each(todo.sql, function(propName, propVal) {
  					$('pre').append('<p>' + propVal + '</p>');
  					$('pre').show();
  				})
  			},
  		});
  	});
  	$('div.DB1').click(function() {
  		$('.spinner').show();
  		var data = {
  			num: 'true'
  		};
  		$.ajax({
  			type: "POST",
  			data: data,
  			url: location + '/db_compare',
  			success: function(resp) {
  				$('.spinner').hide();
  				var todo = $.parseJSON(resp);
  				$('h4#rTitle').text(todo.title);
  				$('pre').empty();
  				$.each(todo.sql, function(propName, propVal) {
  					$('pre').append('<p>' + propVal + '</p>');
  					$('pre').show();
  				})
  			},
  		});
  	});
  });
  </script>
  </body>
</html>
