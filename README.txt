/**
* @mainpage 


<h2 style="border-bottom: 1px solid;">Documentation of the Web-service</h2>
<a href="http://oss.dbc.dk/plone/search?SearchableText=openxid">http://oss.dbc.dk/plone/search?SearchableText=openxid</a>
<p /> 

<h2 style="border-bottom: 1px solid;">Description of the web-service</h2>
In the DANBIB system, marc-records is grouped together in <i>clusters</i>.<br />
A <i>cluster</i> is marc-records which the system has detected as being the same material.<br />
Every marc-record in the cluster has from one to many ids. It can be ISBN, ISBN13, and FAUST etc. 

OpenXId takes the Clusters from DANBIB and extract the numbers from the records and store them in the OpenXId database.

Which numbers OpenXId will be using will be set in the harvest.ini file.

The web-service can be used if you got an id number, and want to see whether this item/record is known by other ids.

The service consists of 2 modules “server.php” and “harvest.php”.  The server.php is the actual web-server. 
Harvest.php is the program updating the OpenXId with data from DANBIB.

Harvest.php will look for updates in DANBIB every 5 minutes.  This can be altered in the harvest_class.php (VOXB_HARVEST_POLL_TIME).

OpenXId is a part of the VoxB web-service. 

<h2 style="border-bottom: 1px solid;">Installation</h2>

Prerequisites:
<ul>
    <li>A Linux server with the following software installed (version either the same or newer):</li>
        <ul>
            <li>Linux: 2.6.32-bpo.5-amd64 #1 SMP Wed Jul 20 09:10:04 UTC 2011 x86_64</li>
            <li>Apache: Apache/2.2.9 (Debian)</li>
            <li>PHP: 5.2.6-1+lenny13</li>
       </ul>

    </li>
</ul>

Pre-installation tasks:
<ul>
    <li>In the php.ini file: always_populate_raw_post_data = On</li>
    <li>There will be a postgres instants running and reachable from the current machine</li>
    <li>Make a database:”<dbase>” </li>
    <li>Make a user with access to openxid: user=<user> password=<pass></li>
    <li>Update the name-servers so  sub-domain: openxid.addi.dk point at the current machine:/data/www/openxid.addi.dk</li>
    <li>Make a directory as the following:
        <ul>
              <li>drwxr-xr-x 3 www-data sideejer 102400 2011-06-30 06:25 /data/tracelogs/</li>
       </ul>
</ul>

Example of an installation of release 0.1 on machine cumbal.dbc.dk
<ul>
    <li>cd /data/www</li>
    <li>svn co https://svn.dbc.dk/repos/php/OpenLibrary/OpenXId/tags/release.0.1 /data/www/openxid/0.1</li>
    <li>cd openxid/0.1/scripts</li>
    <li>cp literals_INSTALL literals</li>
    <li>edit literals
        <ul>
             <li>FORS_CREDENTIALS=user/pass\@server.dk</li>
             <li>OXID_CREDENTIALS=host=<server.dk> dbname=<dbase> user=<user> password=<pass></li>
             <li>OPENXID_URL=http://openxid.addi.dk/0.1</li>           
        </ul>
    </li>
    <li>call: make
        <ul> 
            <li>make test all ”php” modules for syntax errors and makes a copy of the different “INSTALL” files. </li>
            <li>make recognises the following options: all, install, doxygen and compile.</li>
        </ul>
    </li>
    <li>Make the relevant tables, indexes etc. in the database: “php create_tables.php”</li>
    <li>Copy openxid.wsdl_INSTALL to openxid.wsdl and modify the location of the service (at the end of the file)</li>
    <li>Consider copying robots.txt_INSTALL to robots.txt</li>
 </ul>
 
 <h2 style="border-bottom: 1px solid;">Installation of Harvester</h2>
 Pre-installation tasks (harvester):
<ul>
    <li>In DANBIB database:
        <ul>
               <li>Insert in the <i>service</i> table (id, 4) for all the records id in the database.</li> 
                  <li>Update the trigger  ”??” so  the <i>services</i> table is updated with service No. 4</li>
                  <li>make an ORACLE user with delete and select rights on the <i>service</i> table.</li>
           </ul>
    </li>  
    <li>Make a directory as the following:
        <ul>
              <li>drwxr-xr-x 3 www-data sideejer 102400 2011-06-30 06:25 /data/tracelogs/</li>
       </ul>
    </li>
    <li>Insert  “php <www-dir>/openxid/<version>/harvest.php in init.d</i>
</ul>

<h2 style="border-bottom: 1px solid;">Error handling</h2>
If lines, in the logfiles, starting with “ERROR” or “FATAL” please take action. 

<dl>
  <dt>Persons to be contacted:</dt>
      <dd>Hans-Henrik, Martin K., Steen L. F., Allan F.</dd>

   <dt>Persons to be informed:</dt>
      <dd>Per Mogens P., Marianne D., Anders V.</dd>
  </dl>

 <p />
 <h1 style="border-bottom: 1px solid; text-align:left">Copyright information</h1>  
@verbatim
* WebService, Copyright(c) 2009, DBC
* Introduction
* ------------
*
* 
* OpenXId webservice and client 
* 
* 
* License
* -------
* DBC-Software Copyright ?. 2009, Danish Library Center, dbc as.
* 
* This library is Open source middleware/backend software developed and distributed
* under the following licenstype:
* 
* GNU, General Public License Version 3. If any software components linked
* together in this library have legal conflicts with distribution under GNU 3 it
* will apply to the original license type.
* 
* Software distributed under the License is distributed on an "AS IS" basis,
* WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
* for the specific language governing rights and limitations under the
* License.
* 
* Around this software library an Open Source Community is established. Please
* leave back code based upon our software back to this community in accordance to
* the concept behind GNU.
* 
* You should have received a copy of the GNU Lesser General Public
* License along with this library; if not, write to the Free Software
* Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
* 
* @endverbatim
*/