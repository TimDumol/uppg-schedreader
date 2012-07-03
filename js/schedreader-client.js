(function($) {
    var arr = [];
    var $tables = $('table[id="tbl_grade-info"]');
    var $table = $tables.eq($tables.length - 2);
    $table.find('tbody > tr > td:nth-child(2)').each(function(idx) {
      var $this = $(this);
      arr.push(+$this.text());
    });
    $(document.body).append('<div id="sreader-overlay"></div>');
    var $overlay = $('#sreader-overlay');
    $overlay.css({
        position: 'fixed',
        width: '100%',
        height: '200px',
        'border-bottom': 'thin solid #444',
        top: 0,
        left: 0,
        background: '#eee'
    });
    $overlay.append('<div><div>Copy and paste the following to SchedReader:</div>'
      + '<textarea readonly>' + JSON.stringify(arr) + '</textarea></div>');
    $('#sreader-overlay > div').css({
        padding: '1em'
    });
    $('#sreader-overlay textarea').css({
        width: '80%',
        height: '10em'
    });
})(jQuery);
