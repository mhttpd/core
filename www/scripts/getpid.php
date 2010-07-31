<?php

/**
/* This is a helper script to get the PID of the running
/* FastCGI process, returned in a custom header.
 */
header('X-PID: '.getmypid());

?>