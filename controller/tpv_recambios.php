<?php
/*
 * This file is part of FacturaSctipts
 * Copyright (C) 2013-2015  Carlos Garcia Gomez  neorazorx@gmail.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 * 
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

require_model('agente.php');
require_model('albaran_cliente.php');
require_model('almacen.php');
require_model('articulo.php');
require_model('caja.php');
require_model('cliente.php');
require_model('divisa.php');
require_model('ejercicio.php');
require_model('familia.php');
require_model('forma_pago.php');
require_model('grupo_clientes.php');
require_model('impuesto.php');
require_model('serie.php');
require_model('tarifa.php');
require_model('terminal_caja.php');

class tpv_recambios extends fs_controller
{
   public $agente;
   public $almacen;
   public $allow_delete;
   public $articulo;
   public $caja;
   public $cliente;
   public $cliente_s;
   public $divisa;
   public $ejercicio;
   public $equivalentes;
   public $familia;
   public $forma_pago;
   public $imprimir_descripciones;
   public $imprimir_observaciones;
   public $impuesto;
   public $results;
   public $serie;
   public $terminal;
   public $ultimas_compras;
   public $ultimas_ventas;
   
   public function __construct()
   {
      parent::__construct(__CLASS__, 'TPV Genérico', 'TPV');
   }
   
   protected function private_core()
   {
      /// ¿El usuario tiene permiso para eliminar en esta página?
      $this->allow_delete = $this->user->allow_delete_on(__CLASS__);
      
      $this->articulo = new articulo();
      $this->cliente = new cliente();
      $this->cliente_s = FALSE;
      $this->familia = new familia();
      $this->impuesto = new impuesto();
      $this->results = array();
      
      if( isset($_REQUEST['buscar_cliente']) )
      {
         $this->buscar_cliente();
      }
      else if($this->query != '')
      {
         $this->new_search();
      }
      else if( isset($_REQUEST['referencia4precios']) )
      {
         $this->get_precios_articulo();
      }
      else
      {
         $this->agente = $this->user->get_agente();
         $this->almacen = new almacen();
         $this->divisa = new divisa();
         $this->ejercicio = new ejercicio();
         $this->forma_pago = new forma_pago();
         $this->serie = new serie();
         
         $this->imprimir_descripciones = FALSE;
         if( isset($_REQUEST['imprimir_desc']) )
         {
            $this->imprimir_descripciones = TRUE;
         }
         
         $this->imprimir_observaciones = FALSE;
         if( isset($_REQUEST['imprimir_obs']) )
         {
            $this->imprimir_observaciones = TRUE;
         }
         
         if($this->agente)
         {
            $this->caja = FALSE;
            $this->terminal = FALSE;
            $caja = new caja();
            $terminal0 = new terminal_caja();
            foreach($caja->all_by_agente($this->agente->codagente) as $cj)
            {
               if( $cj->abierta() )
               {
                  $this->caja = $cj;
                  $this->terminal = $terminal0->get($cj->fs_id);
                  break;
               }
            }
            
            if(!$this->caja)
            {
               if( isset($_POST['terminal']) )
               {
                  $this->terminal = $terminal0->get($_POST['terminal']);
                  if(!$this->terminal)
                  {
                     $this->new_error_msg('Terminal no encontrado.');
                  }
                  else if( $this->terminal->disponible() )
                  {
                     $this->caja = new caja();
                     $this->caja->fs_id = $this->terminal->id;
                     $this->caja->codagente = $this->agente->codagente;
                     $this->caja->dinero_inicial = floatval($_POST['d_inicial']);
                     $this->caja->dinero_fin = floatval($_POST['d_inicial']);
                     if( $this->caja->save() )
                     {
                        $this->new_message("Caja iniciada con ".$this->show_precio($this->caja->dinero_inicial) );
                     }
                     else
                        $this->new_error_msg("¡Imposible guardar los datos de caja!");
                  }
                  else
                     $this->new_error_msg('El terminal ya no está disponible.');
               }
               else if( isset($_GET['terminal']) )
               {
                  $this->terminal = $terminal0->get($_GET['terminal']);
                  if($this->terminal)
                  {
                     $this->terminal->abrir_cajon();
                     $this->terminal->save();
                  }
                  else
                     $this->new_error_msg('Terminal no encontrado.');
               }
            }
            
            if($this->caja)
            {
               if( isset($_POST['cliente']) )
               {
                  $this->cliente_s = $this->cliente->get($_POST['cliente']);
               }
               else if($this->terminal)
               {
                  $this->cliente_s = $this->cliente->get($this->terminal->codcliente);
               }
               
               if(!$this->cliente_s)
               {
                  foreach($this->cliente->all() as $cli)
                  {
                     $this->cliente_s = $cli;
                     break;
                  }
               }
               
               if( isset($_GET['abrir_caja']) )
               {
                  $this->abrir_caja();
               }
               else if( isset($_GET['cerrar_caja']) )
               {
                  $this->cerrar_caja();
               }
               else if( isset($_POST['cliente']) )
               {
                  if( intval($_POST['numlineas']) > 0 )
                  {
                     $this->nuevo_albaran_cliente();
                  }
               }
               else if( isset($_GET['reticket']) )
               {
                  $this->reimprimir_ticket();
               }
               else if( isset($_GET['delete']) )
               {
                  $this->borrar_ticket();
               }
            }
            else
            {
               $this->results = $terminal0->disponibles();
            }
         }
         else
         {
            $this->new_error_msg('No tienes un <a href="'.$this->user->url().'">agente asociado</a>
               a tu usuario, y por tanto no puedes hacer tickets.');
         }
      }
   }
   
   private function buscar_cliente()
   {
      /// desactivamos la plantilla HTML
      $this->template = FALSE;
      
      $json = array();
      foreach($this->cliente->search($_REQUEST['buscar_cliente']) as $cli)
      {
         $json[] = array('value' => $cli->nombre, 'data' => $cli->codcliente);
      }
      
      header('Content-Type: application/json');
      echo json_encode( array('query' => $_REQUEST['buscar_cliente'], 'suggestions' => $json) );
   }
   
   private function new_search()
   {
      /// cambiamos la plantilla HTML
      $this->template = 'ajax/tpv_recambios';
      
      $codfamilia = '';
      if( isset($_POST['codfamilia']) )
      {
         $codfamilia = $_POST['codfamilia'];
      }
      
      $con_stock = isset($_POST['con_stock']);
      $this->results = $this->articulo->search($this->query, 0, $codfamilia, $con_stock);
      
      /// añadimos el descuento y la cantidad
      foreach($this->results as $i => $value)
      {
         $this->results[$i]->dtopor = 0;
         $this->results[$i]->cantidad = 1;
      }
      
      /// ejecutamos las funciones de las extensiones
      foreach($this->extensions as $ext)
      {
         if($ext->type == 'function' AND $ext->params == 'new_search')
         {
            $name = $ext->text;
            $name($this->db, $this->results);
         }
      }
      
      $cliente = $this->cliente->get($_POST['codcliente']);
      if($cliente)
      {
         if($cliente->regimeniva == 'Exento')
         {
            foreach($this->results as $i => $value)
               $this->results[$i]->iva = 0;
         }
         
         if($cliente->codgrupo)
         {
            $grupo0 = new grupo_clientes();
            $tarifa0 = new tarifa();
            
            $grupo = $grupo0->get($cliente->codgrupo);
            if($grupo)
            {
               $tarifa = $tarifa0->get($grupo->codtarifa);
               if($tarifa)
               {
                  $tarifa->set_precios($this->results);
               }
            }
         }
      }
   }
   
   private function get_precios_articulo()
   {
      /// cambiamos la plantilla HTML
      $this->template = 'ajax/tpv_recambios_precios';
      $this->articulo = $this->articulo->get($_REQUEST['referencia4precios']);
   }
   
   public function get_tarifas_articulo($ref)
   {
      $tarlist = array();
      $articulo = new articulo();
      $tarifa = new tarifa();
      
      foreach($tarifa->all() as $tar)
      {
         $art = $articulo->get($ref);
         if($art)
         {
            $art->dtopor = 0;
            $aux = array($art);
            $tar->set_precios($aux);
            $tarlist[] = $aux[0];
         }
      }
      
      return $tarlist;
   }

   private function nuevo_albaran_cliente()
   {
      $continuar = TRUE;
      
      $almacen = $this->almacen->get($_POST['almacen']);
      if( $almacen )
         $this->save_codalmacen( $almacen->codalmacen );
      else
      {
         $this->new_error_msg('Almacén no encontrado.');
         $continuar = FALSE;
      }
      
      $ejercicio = $this->ejercicio->get_by_fecha($_POST['fecha']);
      if( $ejercicio )
         $this->save_codejercicio( $ejercicio->codejercicio );
      else
      {
         $this->new_error_msg('Ejercicio no encontrado.');
         $continuar = FALSE;
      }
      
      $serie = $this->serie->get($_POST['serie']);
      if( $serie )
         $this->save_codserie( $serie->codserie );
      else
      {
         $this->new_error_msg('Serie no encontrada.');
         $continuar = FALSE;
      }
      
      $forma_pago = $this->forma_pago->get($_POST['forma_pago']);
      if( $forma_pago )
         $this->save_codpago( $forma_pago->codpago );
      else
      {
         $this->new_error_msg('Forma de pago no encontrada.');
         $continuar = FALSE;
      }
      
      $divisa = $this->divisa->get($_POST['divisa']);
      if( $divisa )
         $this->save_coddivisa( $divisa->coddivisa );
      else
      {
         $this->new_error_msg('Divisa no encontrada.');
         $continuar = FALSE;
      }
      
      if( isset($_POST['imprimir_desc']) )
      {
         $this->imprimir_descripciones = TRUE;
         setcookie('imprimir_desc', TRUE, time()+FS_COOKIES_EXPIRE);
      }
      else
      {
         $this->imprimir_descripciones = FALSE;
         setcookie('imprimir_desc', FALSE, time()-FS_COOKIES_EXPIRE);
      }
      
      if( isset($_POST['imprimir_obs']) )
      {
         $this->imprimir_observaciones = TRUE;
         setcookie('imprimir_obs', TRUE, time()+FS_COOKIES_EXPIRE);
      }
      else
      {
         $this->imprimir_observaciones = FALSE;
         setcookie('imprimir_obs', FALSE, time()-FS_COOKIES_EXPIRE);
      }
      
      $albaran = new albaran_cliente();
      
      if( $this->duplicated_petition($_POST['petition_id']) )
      {
         $this->new_error_msg('Petición duplicada. Has hecho doble clic sobre el botón Guardar
               y se han enviado dos peticiones. Mira en <a href="'.$albaran->url().'">'.FS_ALBARANES.'</a>
               para ver si el '.FS_ALBARAN.' se ha guardado correctamente.');
         $continuar = FALSE;
      }
      
      if( $continuar )
      {
         $albaran->fecha = $_POST['fecha'];
         $albaran->codalmacen = $almacen->codalmacen;
         $albaran->codejercicio = $ejercicio->codejercicio;
         $albaran->codserie = $serie->codserie;
         $albaran->codpago = $forma_pago->codpago;
         $albaran->coddivisa = $divisa->coddivisa;
         $albaran->tasaconv = $divisa->tasaconv;
         $albaran->codagente = $this->agente->codagente;
         $albaran->observaciones = $_POST['observaciones'];
         $albaran->numero2 = $_POST['numero2'];
         $albaran->irpf = $serie->irpf;
         $albaran->porcomision = $this->agente->porcomision;
         
         foreach($this->cliente_s->get_direcciones() as $d)
         {
            if($d->domfacturacion)
            {
               $albaran->codcliente = $this->cliente_s->codcliente;
               $albaran->cifnif = $this->cliente_s->cifnif;
               $albaran->nombrecliente = $this->cliente_s->nombrecomercial;
               $albaran->apartado = $d->apartado;
               $albaran->ciudad = $d->ciudad;
               $albaran->coddir = $d->id;
               $albaran->codpais = $d->codpais;
               $albaran->codpostal = $d->codpostal;
               $albaran->direccion = $d->direccion;
               $albaran->provincia = $d->provincia;
               break;
            }
         }
         
         if( is_null($albaran->codcliente) )
         {
            $this->new_error_msg("No hay ninguna dirección asociada al cliente.");
         }
         else if( $albaran->save() )
         {
            $n = floatval($_POST['numlineas']);
            for($i = 1; $i <= $n; $i++)
            {
               if( isset($_POST['referencia_'.$i]) )
               {
                  $articulo = $this->articulo->get($_POST['referencia_'.$i]);
                  if($articulo)
                  {
                     $linea = new linea_albaran_cliente();
                     $linea->idalbaran = $albaran->idalbaran;
                     $linea->referencia = $articulo->referencia;
                     $linea->descripcion = $_POST['desc_'.$i];
                     
                     if( !$serie->siniva OR $this->cliente_s->regimeniva != 'Exento' )
                     {
                        $linea->codimpuesto = $articulo->codimpuesto;
                        $linea->iva = floatval($_POST['iva_'.$i]);
                        $linea->recargo = floatval($_POST['recargo_'.$i]);
                     }
                     
                     $linea->irpf = floatval($_POST['irpf_'.$i]);
                     $linea->pvpunitario = floatval($_POST['pvp_'.$i]);
                     $linea->cantidad = floatval($_POST['cantidad_'.$i]);
                     $linea->dtopor = floatval($_POST['dto_'.$i]);
                     $linea->pvpsindto = ($linea->pvpunitario * $linea->cantidad);
                     $linea->pvptotal = floatval($_POST['neto_'.$i]);
                     
                     if( $linea->save() )
                     {
                        /// descontamos del stock
                        $articulo->sum_stock($albaran->codalmacen, 0 - $linea->cantidad);
                        
                        $albaran->neto += $linea->pvptotal;
                        $albaran->totaliva += ($linea->pvptotal * $linea->iva/100);
                        $albaran->totalirpf += ($linea->pvptotal * $linea->irpf/100);
                        $albaran->totalrecargo += ($linea->pvptotal * $linea->recargo/100);
                     }
                     else
                     {
                        $this->new_error_msg("¡Imposible guardar la linea con referencia: ".$linea->referencia);
                        $continuar = FALSE;
                     }
                  }
                  else
                  {
                     $this->new_error_msg("Artículo no encontrado: ".$_POST['referencia_'.$i]);
                     $continuar = FALSE;
                  }
               }
            }
            
            if($continuar)
            {
               /// redondeamos
               $albaran->neto = round($albaran->neto, FS_NF0);
               $albaran->totaliva = round($albaran->totaliva, FS_NF0);
               $albaran->totalirpf = round($albaran->totalirpf, FS_NF0);
               $albaran->totalrecargo = round($albaran->totalrecargo, FS_NF0);
               $albaran->total = $albaran->neto + $albaran->totaliva - $albaran->totalirpf + $albaran->totalrecargo;
               
               if( abs(floatval($_POST['tpv_total']) - $albaran->total) >= .02 )
               {
                  $this->new_error_msg("El total difiere entre la vista y el controlador (".$_POST['tpv_total'].
                          " frente a ".$albaran->total."). Debes informar del error.");
                  $albaran->delete();
               }
               else if( $albaran->save() )
               {
                  $this->new_message("<a href='".$albaran->url()."'>".FS_ALBARAN."</a> guardado correctamente.");
                  
                  $this->imprimir_ticket( $albaran, floatval($_POST['num_tickets']) );
                  
                  /// actualizamos la caja
                  $this->caja->dinero_fin += $albaran->total;
                  $this->caja->tickets += 1;
                  $this->caja->ip = $_SERVER['REMOTE_ADDR'];
                  if( !$this->caja->save() )
                  {
                     $this->new_error_msg("¡Imposible actualizar la caja!");
                  }
               }
               else
                  $this->new_error_msg("¡Imposible actualizar el <a href='".$albaran->url()."'>".FS_ALBARAN."</a>!");
            }
            else if( $albaran->delete() )
            {
               $this->new_message(FS_ALBARAN." eliminado correctamente.");
            }
            else
               $this->new_error_msg("¡Imposible eliminar el <a href='".$albaran->url()."'>".FS_ALBARAN."</a>!");
         }
         else
            $this->new_error_msg("¡Imposible guardar el ".FS_ALBARAN."!");
      }
   }
   
   private function abrir_caja()
   {
      if($this->user->admin)
      {
         if($this->terminal)
         {
            $this->terminal->abrir_cajon();
            $this->terminal->save();
         }
      }
      else
         $this->new_error_msg('Sólo un administrador puede abrir la caja.');
   }
   
   private function cerrar_caja()
   {
      $this->caja->fecha_fin = Date('d-m-Y H:i:s');
      if( $this->caja->save() )
      {
         if( $this->terminal )
         {
            $this->terminal->add_linea_big("\nCIERRE DE CAJA:\n");
            $this->terminal->add_linea("Empleado: ".$this->user->codagente." ".$this->agente->get_fullname()."\n");
            $this->terminal->add_linea("Caja: ".$this->caja->fs_id."\n");
            $this->terminal->add_linea("Fecha inicial: ".$this->caja->fecha_inicial."\n");
            $this->terminal->add_linea("Dinero inicial: ".$this->show_precio($this->caja->dinero_inicial, FALSE, FALSE)."\n");
            $this->terminal->add_linea("Fecha fin: ".$this->caja->show_fecha_fin()."\n");
            $this->terminal->add_linea("Dinero fin: ".$this->show_precio($this->caja->dinero_fin, FALSE, FALSE)."\n");
            $this->terminal->add_linea("Diferencia: ".$this->show_precio($this->caja->diferencia(), FALSE, FALSE)."\n");
            $this->terminal->add_linea("Tickets: ".$this->caja->tickets."\n\n");
            $this->terminal->add_linea("Dinero pesado:\n\n\n");
            $this->terminal->add_linea("Observaciones:\n\n\n\n");
            $this->terminal->add_linea("Firma:\n\n\n\n\n\n\n");
            
            /// encabezado común para los tickets
            $this->terminal->add_linea_big( $this->terminal->center_text($this->empresa->nombre, 16)."\n");
            $this->terminal->add_linea( $this->terminal->center_text($this->empresa->lema) . "\n\n");
            $this->terminal->add_linea( $this->terminal->center_text($this->empresa->direccion . " - " . $this->empresa->ciudad) . "\n");
            $this->terminal->add_linea( $this->terminal->center_text("CIF: " . $this->empresa->cifnif) . chr(27).chr(105) . "\n\n"); /// corta el papel
            $this->terminal->add_linea( $this->terminal->center_text($this->empresa->horario) . "\n");
            
            $this->terminal->abrir_cajon();
            $this->terminal->save();
            
            /// recargamos la página
            header('location: '.$this->url().'&terminal='.$this->terminal->id);
         }
         else
         {
            /// recargamos la página
            header('location: '.$this->url());
         }
      }
      else
         $this->new_error_msg("¡Imposible cerrar la caja!");
   }
   
   private function reimprimir_ticket()
   {
      $albaran = new albaran_cliente();
      
      if($_GET['reticket'] == '')
      {
         foreach($albaran->all() as $alb)
         {
            $alb0 = $alb;
            break;
         }
      }
      else
         $alb0 = $albaran->get_by_codigo($_GET['reticket']);
      
      if($alb0)
      {
         $this->imprimir_ticket($alb0, 1, FALSE);
      }
      else
         $this->new_error_msg("Ticket no encontrado.");
   }
   
   private function borrar_ticket()
   {
      $albaran = new albaran_cliente();
      $alb = $albaran->get_by_codigo($_GET['delete']);
      if($alb)
      {
         if($alb->ptefactura)
         {
            $articulo = new articulo();
            foreach($alb->get_lineas() as $linea)
            {
               $art0 = $articulo->get($linea->referencia);
               if($art0)
               {
                  $art0->sum_stock($alb->codalmacen, $linea->cantidad);
                  $art0->save();
               }
            }
            
            if( $alb->delete() )
            {
               $this->new_message("Ticket ".$_GET['delete']." borrado correctamente.");
               
               /// actualizamos la caja
               $this->caja->dinero_fin -= $alb->total;
               $this->caja->tickets -= 1;
               if( !$this->caja->save() )
               {
                  $this->new_error_msg("¡Imposible actualizar la caja!");
               }
            }
            else
               $this->new_error_msg("¡Imposible borrar el ticket ".$_GET['delete']."!");
         }
         else
            $this->new_error_msg('No se ha podido borrar este '.FS_ALBARAN.' porque ya está facturado.');
      }
      else
         $this->new_error_msg("Ticket no encontrado.");
   }
   
   private function imprimir_ticket($albaran, $num_tickets = 1, $cajon = TRUE)
   {
      if($this->terminal)
      {
         if($cajon)
         {
            $this->terminal->abrir_cajon();
         }
         
         while($num_tickets > 0)
         {
            $linea = "\nTicket: " . $albaran->codigo;
            $linea .= " " . $albaran->fecha;
            $linea .= " " . $albaran->show_hora(FALSE) . "\n";
            $this->terminal->add_linea($linea);
            $this->terminal->add_linea("Cliente: " . $albaran->nombrecliente . "\n");
            $this->terminal->add_linea("Empleado: " . $albaran->codagente . "\n\n");
            
            if($this->imprimir_observaciones)
            {
               $this->terminal->add_linea('Observaciones: '.$albaran->observaciones."\n\n");
            }
            
            $this->terminal->add_linea(sprintf("%3s", "Ud.")." ".sprintf("%-25s", "Articulo")." ".sprintf("%10s", "TOTAL")."\n");
            $this->terminal->add_linea(sprintf("%3s", "---")." ".sprintf("%-25s", "-------------------------")." ".sprintf("%10s", "----------")."\n");
            foreach($albaran->get_lineas() as $col)
            {
               if($this->imprimir_descripciones)
               {
                  $linea = sprintf("%3s", $col->cantidad)." ".sprintf("%-25s", substr($col->descripcion, 0, 24))." "
                     .sprintf("%10s", $this->show_numero($col->total_iva()))."\n";
               }
               else
               {
                  $linea = sprintf("%3s", $col->cantidad)." ".sprintf("%-25s", $col->referencia)." "
                     .sprintf("%10s", $this->show_numero($col->total_iva()))."\n";
               }
               
               $this->terminal->add_linea($linea);
            }
            
            $linea = "----------------------------------------\n"
               .$this->terminal->center_text(
                  "IVA: ".$this->show_precio($albaran->totaliva, $albaran->coddivisa, FALSE).'   '.
                  "Total: ".$this->show_precio($albaran->total, $albaran->coddivisa, FALSE)
               )."\n\n\n\n";
            $this->terminal->add_linea($linea);
            
            $this->terminal->add_linea_big( $this->terminal->center_text($this->empresa->nombre, 16)."\n");
            
            if($this->empresa->lema != '')
            {
               $this->terminal->add_linea( $this->terminal->center_text($this->empresa->lema) . "\n\n");
            }
            else
               $this->terminal->add_linea("\n");
            
            $this->terminal->add_linea( $this->terminal->center_text($this->empresa->direccion . " - " . $this->empresa->ciudad) . "\n");
            $this->terminal->add_linea( $this->terminal->center_text("CIF: " . $this->empresa->cifnif) . chr(27).chr(105) . "\n\n"); /// corta el papel
            
            if($this->empresa->horario != '')
               $this->terminal->add_linea( $this->terminal->center_text($this->empresa->horario) . "\n");
            
            $num_tickets--;
         }
         
         $this->terminal->save();
      }
   }
}
