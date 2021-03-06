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

require_model('familia.php');

class ventas_familias extends fs_controller
{
   public $familia;
   public $madre;
   public $resultados;
   
   public function __construct()
   {
      parent::__construct(__CLASS__, 'Familias', 'ventas', FALSE, TRUE);
   }
   
   protected function process()
   {
      $this->familia = new familia();
      
      $this->madre = NULL;
      if( isset($_REQUEST['madre']) )
      {
         $this->madre = $_REQUEST['madre'];
      }
      
      if( isset($_POST['ncodfamilia']) )
      {
         $fam = $this->familia->get($_POST['ncodfamilia']);
         if($fam)
         {
            $this->new_error_msg('La familia <a href="'.$fam->url().'">'.$_POST['ncodfamilia'].'</a> ya existe.');
         }
         else
         {
            $fam = new familia();
            $fam->codfamilia = $_POST['ncodfamilia'];
            $fam->descripcion = $_POST['ndescripcion'];
            $fam->madre = $this->madre;
            if( $fam->save() )
            {
               Header('location: ' . $fam->url());
            }
            else
               $this->new_error_msg("¡Imposible guardar la familia!");
         }
      }
      else if( isset($_GET['delete']) )
      {
         $fam = new familia();
         $fam->codfamilia = $_GET['delete'];
         if( $fam->delete() )
         {
            $this->new_message("Familia ".$_GET['delete']." eliminada correctamente");
         }
         else
            $this->new_error_msg("¡Imposible eliminar la familia ".$_GET['delete']."!");
      }
      
      if($this->query != '')
      {
         $this->resultados = $this->familia->search($this->query);
      }
      else
         $this->resultados = $this->familia->madres();
   }
   
   public function total_familias()
   {
      $data = $this->db->select("SELECT COUNT(codfamilia) as total FROM familias;");
      if($data)
      {
         return intval($data[0]['total']);
      }
      else
         return 0;
   }
}
