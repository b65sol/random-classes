<?php
use Spipu\Html2Pdf\Html2Pdf;
use Spipu\Html2Pdf\CssConverter;
use Spipu\Html2Pdf\Exception\Html2PdfException;
use Spipu\Html2Pdf\Exception\ExceptionFormatter;

class Html2PdfTableOptimizer {
  protected $pdf;
  public $min_percent = 6.5;
  public $fontsize = '';
  public $fontfamily = '';
  public $column_class_prefix = 'col';

  public $header_classes_on_cells = false;

  protected $header_row = [];
  protected $data_rows = [];

  protected $current_data_row = [];

  /**
   * Accepts a TCPDF object.
   */
  public function __construct($pdf, $fontsize = '', $family = '') {
    $this->pdf = $pdf;
    if($fontsize) {
      $cssconv = new CssConverter();
      $this->fontsize = 72. * ($cssconv->convertToMM($fontsize)) / 25.4;
    }
    $this->fontfamily = $family;
  }

  public function measure_width($str) {
    return $this->pdf->GetStringWidth(strip_tags($str), $this->fontfamily, '', $this->fontsize);
  }

  public function set_minimum_percentage_based_on_string($str, $table_width = '100%') {
    $margins = $this->pdf->getMargins();
    $space = $this->pdf->getHTMLUnitToUnits($table_width, $this->pdf->getPageWidth() - $margins['left'] - $margins['right']);
    $this->min_percent = ($this->measure_width($str) / $space) * 100;
  }

  public function reset_data() {
    $this->header_row = $this->data_rows = $this->column_stats = $this->current_data_row = [];
  }

  public function add_header_cell($content, $class = '') {
    $this->header_row[] = [$content, $class];
  }

  public function start_data_row() {
    $this->current_data_row = [];
  }

  public function end_data_row() {
    $this->data_rows[] = $this->current_data_row;
    $this->current_data_row = [];
  }

  public function add_data_row_cell($content, $class = '') {
    $this->current_data_row[] = [$content, $class];
  }

  protected function longest_word($str) {
    $words = preg_split('/[\s\,\.\?]+/', $str);
    usort($words, function($a,$b) {return strlen($b) - strlen($a); } );
    return $words[0];
  }

  /**
   * Size the columns evenly, except for specific specified columns in $column_widths
   * @param String $table_width How wide our table is in HTML units.
   * @param Array $column_widths Known column sizes. Use null to skip a column. Example:
   *  2nd column is 10mm: [null, 10mm]
   */
  public function determine_column_widths_evenly($table_width = '100%', $column_widths = []) {
    $margins = $this->pdf->getMargins();
    $space = $this->pdf->getHTMLUnitToUnits($table_width, $this->pdf->getPageWidth() - $margins['left'] - $margins['right']);
    $colwidths = [];
    $cssconv = new CssConverter();
    for($i = 0; $i < count($this->header_row); $i++) {
      if(!empty($column_widths[$i])) {
        $colwidths[$i] = $cssconv->convertToMM($column_widths[$i]) / $space;
      } else {
        $colwidths[$i] = null;
      }
    }
    $remaining_available = 1 - array_sum($colwidths);
    $remaining_count = 0;
    foreach($colwidths as $column => $size) {
      if($size == null) {
        $remaining_count++;
      }
    }

    $colwidths = array_map(function($a) use ($remaining_available, $remaining_count) {
      return $a == null ? $remaining_available / $remaining_count : $a;
    }, $colwidths);

    $css = '';
    foreach($colwidths as $column => $width) {
      $css .= ".{$this->column_class_prefix}{$column} { width:".sprintf('%.2f', $width*100)."% }\n";
    }
    return $css;
  }

  /**
   * Size the columns based on the minimum width to support the longest word in the column.
   * @param String $table_width How wide our table is in HTML units.
   * @param String $pad_string Padding string to add to content to account for padding and text style differences.
   */
  public function determine_column_widths_by_minimum_strategy($table_width = "100%", $pad_string = 'aaa') {
    $space = $this->pdf->getHTMLUnitToUnits($table_width, $this->pdf->getPageWidth() - $margins['left'] - $margins['right']);
    foreach($this->header_row as $column => $rowcell) {
      $matrix[$column]['word'][] = $this->measure_width($pad_string.$this->longest_word($rowcell[0]));
      $matrix[$column]['full'][] = $this->measure_width($pad_string.$rowcell[0]);
    }
    foreach($this->data_rows as $row => $data) {
      foreach($data as $column => $cell) {
        $matrix[$column]['full'][] = $this->measure_width($pad_string.$cell[0]);
        $matrix[$column]['word'][] = $this->measure_width($pad_string.$this->longest_word($cell[0]));
      }
    }
    $colword = [];
    $colwidths = [];
    $colfull = [];
    foreach($matrix as $column => $split) {
      $colwidths[$column] = max($split['word'])/$space;
      $colfull[$column] = max($split['full']);
    }

    if(array_sum($colwidths) <= 1) {
      //Find which have the biggest difference between their full string length and
      //their longest word.
      $available_expansion = 1 - array_sum($colwidths);
      $colwidths_diff = array_map(function($a) {
        return max($a['full']) - max($a['word']);
      }, $matrix);
      $total = array_sum($colwidths_diff);
      if($total != 0) {
        foreach(array_keys($colwidths) as $column) {
          $colwidths[$column] += ($colwidths_diff[$column]/$total)*$available_expansion;
        }
      }
    }

    $extra = 1 - array_sum($colwidths);

    //Initially let's just distribute the rest of expansion/shrinkage....
    $colwidths = array_map(function($a) use ($extra, $colwidths) {
      return $a += ($extra / count($colwidths));
    }, $colwidths);

    $css = '';
    foreach($colwidths as $column => $width) {
      $css .= ".{$this->column_class_prefix}{$column} { width:".sprintf('%.2f', $width*100)."% }\n";
    }
    return $css;
  }

  public function determine_column_widths_by_weighting() {
    $running_mean_src = [];
    $matrix = [];
    foreach($this->header_row as $column => $rowcell) {
      $matrix[0][$column] = $this->measure_width($rowcell[0]);
    }
    foreach($this->data_rows as $row => $data) {
      foreach($data as $column => $cell) {
        $matrix[1+$row][$column] = $this->measure_width($cell[0]);
      }
    }
    foreach($matrix as $row) {
      $totalwidth = array_sum($row);
      if($totalwidth == 0) {
        continue;
      }
      foreach($row as $column => $width) {
        $running_mean_src[$column][] = $width/$totalwidth;
      }
    }
    $colwidths = [];
    $adjustment = 0;
    $toosmall = [];
    foreach($running_mean_src as $column => $percentages) {
      $avgwidthperc = array_sum($percentages)/count($percentages);

      if($avgwidthperc < ($this->min_percent/100)) {
        $colwidths[$column] = $this->min_percent/100;
        $width_available -= $this->min_percent/100;
        $adjustment += ($this->min_percent/100) - $avgwidthperc;
        $toosmall[] = $column;
      } else {
        $colwidths[$column] = $avgwidthperc;
        $width_available -= $avgwidthperc;
      }
    }
    if($adjustment > 0 && count($toosmall) != count($colwidths)) {
      $average_portion = $adjustment / (count($colwidths) - count($toosmall));
      foreach(array_keys($colwidths) as $column) {
        if(in_array($column, $toosmall)) {
          continue;
        }
        if($colwidths[$column]-$average_portion < $this->min_percent/100) {
          $toosmall[] = $column;
        }
      }
    }
    if($adjustment > 0 && count($toosmall) != count($colwidths)) {
      $average_portion = $adjustment / (count($colwidths) - count($toosmall));
      foreach(array_keys($colwidths) as $column) {
        if(in_array($column, $toosmall)) {
          continue;
        }
        //There has to be a way to scale this proportionally with column size, but I'm drawing a blank.
        $colwidths[$column] -= $average_portion;
      }
    }
    $css = '';
    foreach($colwidths as $column => $width) {
      $css .= ".{$this->column_class_prefix}{$column} { width:".sprintf('%.2f', $width*100)."% }\n";
    }
    return $css;
  }

  /**
   * Returns HTML for the table and CSS width rules for sizing.
   */
  public function render_html() {
    $output = "<table>";
    $output .= '<thead><tr>';
    foreach($this->header_row as $column => $header) {
      $output .= "<th class=\"{$this->column_class_prefix}{$column} {$header[1]}\">".$header[0]."</th>";
    }
    $output .= "</tr></thead>";
    $output .= "<tbody>\n";
    foreach($this->data_rows as $index => $row) {
      $rowclass = 'row-'.(($index&1) ? 'odd' : 'even');
      $output .= "<tr class=\"$rowclass\">\n";
      foreach($row as $column => $cell) {
        $finalclass = $this->header_classes_on_cells ? "{$this->header_row[$column][1]} {$cell[1]}" : $cell[1];
        $output .= "<td class=\"{$this->column_class_prefix}{$column} $finalclass\">".$cell[0]."</td>\n";
      }
      $output .= "</tr>\n";
    }
    $output .= "</tbody>\n";
    $output .= '</table>';

    return $output;
  }
}
