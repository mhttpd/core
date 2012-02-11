<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN">
<html>
  <head>
    <title>MiniHTTPD Status</title>
    <style type="text/css">
      body {font-family: Arial, Verdana, Sans; font-size: 0.9em; color:#222222}
		  hr {border: 0; height: 1px; background-color: #888888}
      table {font-size: 0.9em;}
    </style>
  </head>
  <body>
    <h1>MiniHTTPD Server Status</h1>
    <p>Server Version: :version:</p>
    <hr>
    <h3>Server traffic summary</h3>
    <table>
			<tr><td width="100px">Running since: </td><td>:launched:</td></tr>
			<tr><td>Total received: </td><td>:trafficup:</td></tr>
			<tr><td>Total sent: </td><td>:trafficdown:</td></tr>
		</table>
    <br /><hr>
    <h3>Connected clients</h3>
    <pre>:clients:</pre>
    <br /><hr>
    <h3>FastCGI scoreboard</h3>
    <pre>:fcgiscoreboard:</pre>
    <br /><hr>
    <h3>Aborted requests</h3>
    <pre>:aborted:</pre>
    <br /><hr>		
    <h3>Request handlers</h3>
    <pre>:requesthandlers:</pre>
		<br /><hr>
    <address>:signature:</address>
    <br /><br />
  </body>
</html>
