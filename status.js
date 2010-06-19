/*
  Copyright © 2009 Stéphane Glondu <steph@glondu.net>
  Copyright © 2009 Mehdi Dogguy <dogguy@pps.jussieu.fr>

  This program is free software: you can redistribute it and/or modify
  it under the terms of the GNU General Public License as published by
  the Free Software Foundation, either version 3 of the License, or
  (at your option) any later version.

  Dependencies: jquery.
*/

function update () {
    if ($("#good").is(":checked")) {
        $(".good").show();
    } else {
        $(".good").hide();
    }
    if ($("#bad").is(":checked")) {
        $(".bad").show();
    } else {
        $(".bad").hide();
    }
    $("#count").html("("+ $(".bad , .good").filter(":visible").length  +")");
};

function init () {
    $("#jsmode").append("Filter by status: "
                      + " <input type=\"checkbox\" checked=\"checked\" id=\"good\" />good"
                      + " <input type=\"checkbox\" checked=\"checked\" id=\"bad\" />bad"
                      + " <span id=\"count\"></span>"
                 );
    $("#good").click(update);
    $("#bad").click(update);
    update();
}

$(document).ready(init);
